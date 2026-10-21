<?php

namespace App\Services\TopicShort;

use App\Models\CharacterPortrait;
use App\Models\TopicShortScene;
use App\Services\Claude\AiUsage;
use App\Services\Film\FilmClaude;
use App\Services\Script\ScriptWriterException;
use App\Support\DragonBall;

/** Claude viết lại prompt cảnh short khi AI vẽ ảnh từ chối (bạo lực / đau đớn, tên nhân vật có bản quyền...). */
final class ScenePromptFixer
{
    public const SYSTEM = <<<'PROMPT'
    You rewrite one image prompt for a vertical (9:16) anime / manga style picture in a short recap video. The image AI refused the old prompt; its explanation is given.
    - Fix exactly what the AI objected to, and follow its suggestion when it gives one.
    - No violence shown on screen: no pain, injuries, blood, choking, gripping that hurts, destruction of living things, corpses. Keep the drama with expressions, poses, aura, energy, speed lines, dramatic sky and lighting, silhouettes, aftermath framing.
    - No franchise, show, author or character names: call characters "Character 1", "Character 2"... in the order given, and describe their look instead.
    - Keep the moment, the setting and the camera idea recognisable.
    - English, under 80 words, prompt only.
    PROMPT;

    /** @throws ScriptWriterException */
    public static function rewrite(TopicShortScene $scene, string $refusal): string
    {
        $short = $scene->topicShort;
        $characters = collect($short->characters ?? [])->filter(fn ($c) => in_array($c['name'], $scene->characters ?? [], true))->values()
            ->map(fn ($c, $i) => 'Character '.($i + 1).": {$c['name']}, ".DragonBall::scrub((string) ($c['look'] ?? '')))->implode("\n");
        $text = "Characters:\n".($characters ?: '(none)')."\n\nOld prompt:\n{$scene->image_prompt}\n\nThe image AI refused it and said:\n".($refusal ?: '(no explanation)');

        $data = AiUsage::withContext(['user_id' => $short->user_id, 'subject' => "topic_short:{$short->id}"], fn () => FilmClaude::json(
            self::SYSTEM,
            $text,
            ['type' => 'object', 'additionalProperties' => false, 'required' => ['image_prompt'], 'properties' => ['image_prompt' => ['type' => 'string']]],
            'topic_short',
            "topic_short_scene:{$scene->id}",
            1000,
            fn () => ['image_prompt' => self::fallback((string) $scene->image_prompt)],
        ));
        $prompt = trim((string) ($data['image_prompt'] ?? ''));
        if (mb_strlen($prompt) < 15) {
            throw new ScriptWriterException('Claude không trả prompt mới.');
        }

        return mb_substr($prompt, 0, 2000);
    }

    public const PORTRAIT_SYSTEM = <<<'PROMPT'
    You rewrite an image prompt for one black-and-white manga character picture (vertical 9:16). The image AI refused the previous prompt; its explanation is given.
    - The AI refuses copyrighted characters: write it as an ORIGINAL character inspired by the given look. No franchise, show, author or character names, no trademarked terms; describe hair, face, eyes, build, outfit, accessories in your own words.
    - Expression: keep the requested one only if the AI did not object to it. When the AI suggests a different expression or removing effects (speed lines, debris, sweat drops, question marks, dot eyes, screaming), USE ITS SUGGESTION exactly, even if the expression becomes calmer. Nothing frightening or violent: no blood, injuries, cracks in the body, screaming in agony.
    - Follow the AI's suggestion when it gives one.
    - The AI remembers refused requests and blocks anything that looks like a retry. Make it a clearly NEW image: different framing (e.g. waist-up three-quarter view, or full-body dynamic pose instead of a close-up bust), different pose and gesture, a simple background element (sky, ground, speed-line burst), fresh wording and sentence order. Soften the most iconic identifying marks into generic ones.
    - Follow the AI's own suggestion (it usually offers an original character with a similar look and a calmer variant) as closely as possible: it is the only version it will accept.
    - Keep: black and white ink, screentone shading, clean bold lines, 1990s shonen manga look, no text or speech bubbles.
    - English, under 90 words, prompt only.
    PROMPT;

    /** Prompt mới cho chân dung nhân vật bị từ chối. */
    public static function rewritePortrait(CharacterPortrait $portrait, string $refusal): string
    {
        [, $look] = DragonBall::CHARACTERS[$portrait->character];
        $previous = $portrait->prompt ?: DragonBall::prompt($portrait->character, $portrait->expression);
        $expression = DragonBall::EXPRESSIONS[$portrait->expression][1];
        $text = 'Look: '.DragonBall::scrub($look)."\nExpression: {$expression}\n\nPrevious prompt:\n".DragonBall::scrub($previous)
            ."\n\nThe image AI refused it and said:\n".($refusal ?: '(no explanation)');

        try {
            $data = AiUsage::withContext(['user_id' => $portrait->user_id, 'subject' => null], fn () => FilmClaude::json(
                self::PORTRAIT_SYSTEM,
                $text,
                ['type' => 'object', 'additionalProperties' => false, 'required' => ['image_prompt'], 'properties' => ['image_prompt' => ['type' => 'string']]],
                'topic_short',
                "character_portrait:{$portrait->id}",
                1000,
                fn () => ['image_prompt' => DragonBall::prompt($portrait->character, $portrait->expression, safe: true)],
            ));
            $prompt = trim((string) ($data['image_prompt'] ?? ''));
        } catch (ScriptWriterException) {
            $prompt = '';
        }

        return mb_strlen($prompt) >= 15 ? mb_substr($prompt, 0, 2000) : DragonBall::prompt($portrait->character, $portrait->expression, safe: true);
    }

    /** Viết lại theo luật (không có Claude): bỏ tên riêng và các từ bạo lực. */
    public static function fallback(string $prompt): string
    {
        $prompt = DragonBall::scrub($prompt);
        $soft = [
            'collapsing in pain' => 'startled', 'in pain' => 'startled', 'screaming in agony' => 'shouting', 'agony' => 'shock',
            'blood' => 'dust', 'bloody' => 'dusty', 'injured' => 'tired', 'wounded' => 'tired', 'dead' => 'fallen', 'corpse' => 'figure',
            'kill' => 'defeat', 'killing' => 'defeating', 'killed' => 'defeated', 'destroyed' => 'windswept', 'destroying' => 'shaking',
            'monster' => 'giant', 'grips' => 'touches', 'gripping' => 'holding', 'choking' => 'holding', 'punching' => 'facing', 'impaled' => 'struck',
        ];
        foreach ($soft as $word => $replacement) {
            $prompt = preg_replace('/\b'.preg_quote($word, '/').'\b/iu', $replacement, $prompt);
        }

        return trim((string) $prompt);
    }
}
