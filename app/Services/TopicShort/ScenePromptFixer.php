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
    - Keep every character's real name and form exactly as given (e.g. "Son Goku, Super Saiyan", "Vegeta, Super Saiyan Blue") and their look: the picture is for a Dragon Ball fan comic and must show those exact characters. Only soften the action, expression and effects.
    - Keep the moment, the setting and the camera idea recognisable.
    - English, under 80 words, prompt only.
    PROMPT;

    /** @throws ScriptWriterException */
    public static function rewrite(TopicShortScene $scene, string $refusal): string
    {
        $short = $scene->topicShort;
        $characters = collect($short->characters ?? [])->filter(fn ($c) => in_array($c['name'], $scene->characters ?? [], true))->values()
            ->map(fn ($c) => "- {$c['name']}: ".($c['look'] ?? ''))->implode("\n");
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
    An image AI refused to draw a black-and-white manga portrait of a character with a given facial expression; its explanation is given.
    The character's name, form (e.g. Super Saiyan), look (hair, face, eyes, build, outfit, marks) and the framing stay EXACTLY as they are: the picture must still look like the same character as the other portraits in the set. You only rewrite the expression line.
    - Write a short, gentle, non-violent facial expression and pose (under 25 words) close to the requested one: no anger, screaming, pain, fear, sweat drops, speed lines, debris or impact effects.
    - When the AI suggests a calmer variant or removing an effect, use its suggestion.
    - English, expression only: do not describe the character, the style or the background.
    PROMPT;

    /** Prompt mới cho chân dung nhân vật bị từ chối: giữ nguyên ngoại hình và khung hình, Claude chỉ viết lại biểu cảm. */
    public static function rewritePortrait(CharacterPortrait $portrait, string $refusal): string
    {
        [, $look] = DragonBall::CHARACTERS[$portrait->character];
        $expression = DragonBall::EXPRESSIONS[$portrait->expression][1];
        $text = 'Character look (kept as is): '.$look."\nRequested expression: {$expression}"
            ."\n\nThe image AI refused it and said:\n".($refusal ?: '(no explanation)');

        try {
            $data = AiUsage::withContext(['user_id' => $portrait->user_id, 'subject' => null], fn () => FilmClaude::json(
                self::PORTRAIT_SYSTEM,
                $text,
                ['type' => 'object', 'additionalProperties' => false, 'required' => ['expression'], 'properties' => ['expression' => ['type' => 'string']]],
                'topic_short',
                "character_portrait:{$portrait->id}",
                500,
                fn () => ['expression' => $expression],
            ));
            $softer = trim((string) ($data['expression'] ?? ''), " \n\t.");
        } catch (ScriptWriterException) {
            $softer = '';
        }
        $softer = mb_strlen($softer) >= 5 ? mb_substr($softer, 0, 300) : $expression;

        return DragonBall::prompt($portrait->character, $portrait->expression, expressionText: $softer);
    }

    /** Viết lại theo luật (không có Claude): thay các từ bạo lực, giữ tên nhân vật. */
    public static function fallback(string $prompt): string
    {
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
