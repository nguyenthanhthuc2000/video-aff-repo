<?php

namespace App\Services\TopicShort;

use App\Models\CharacterPortrait;
use App\Models\TopicShortScene;
use App\Services\Claude\AiUsage;
use App\Services\Film\FilmClaude;
use App\Services\Script\ScriptWriterException;
use App\Support\DragonBall;

/** Claude viết prompt cảnh short: khớp lời kể khi bấm Muse vẽ, và viết lại khi AI vẽ ảnh từ chối (bạo lực / đau đớn, tên nhân vật có bản quyền...). */
final class ScenePromptFixer
{
    public const SYSTEM = <<<'PROMPT'
    You rewrite one image prompt for a vertical (9:16) anime / manga style picture in a short recap video. The image AI refused the old prompt; its explanation is given.
    - When the AI offers a "makeable alternative" (or any suggestion), build the new prompt from that alternative: same pose, expression, setting and effects it describes. It overrides the narration's action.
    - Remove EVERY element the AI named as declined (e.g. "shocked", "surprised", "sneeze", "sniffle", a glow from the nose, a composition "family"), including close synonyms and the same idea in other words. The AI remembers declined compositions: a similar picture with softer words is declined again, so change the expression, pose and framing, not just the wording.
    - No violence shown on screen: no pain, injuries, blood, choking, gripping that hurts, destruction of living things, corpses. Keep the drama with expressions, poses, aura, energy, speed lines, dramatic sky and lighting, silhouettes, aftermath framing.
    - Keep every character's real name and form exactly as given (e.g. "Son Goku, Super Saiyan", "Vegeta, Super Saiyan Blue") and their look: the picture is for a Dragon Ball fan comic and must show those exact characters. Change the action, expression, pose and effects as needed.
    - Only when the AI gives no alternative and names no declined element: keep the MAIN ACTION of the narration line and soften just the objected words, rather than a generic calm pose.
    - English, under 80 words, prompt only.
    PROMPT;

    public const SCENE_SYSTEM = <<<'PROMPT'
    You write the image prompt for ONE scene of a vertical (9:16) anime recap short. The picture is shown while the narrator reads this scene's line, so it must show exactly what the line says.
    - Read the narration line (Vietnamese) and the on-screen text; picture the single most telling moment of that line: who is there, what they do, their expression and pose, where, from which camera angle.
    - Name the characters by their real names and forms (e.g. "Son Goku, Super Saiyan", "Frieza, final form") and keep their canonical look as given.
    - When the line is abstract (a question, a comparison, a number), show the character it is about in a pose and expression that carries that idea (e.g. a mystery: the character half in shadow, thoughtful look).
    - Add setting, lighting, mood and camera (close-up / low angle / full body). One clear subject, readable on a phone.
    - No violence shown on screen: no blood, injuries, pain, corpses; keep the drama with aura, energy, speed lines, sky and lighting. Destruction of planets / stars is fine as glowing light and explosions in space. Funny moments stay comedic (exaggerated expressions).
    - Show the main action of the line, never a generic calm pose when the line describes an action.
    - No text, captions or speech bubbles in the picture.
    - English, under 90 words, prompt only (style and frame are added separately).
    PROMPT;

    /**
     * Prompt mới khớp với lời kể / chữ trên màn của cảnh (khi bấm Muse vẽ), có cảnh trước / sau để hiểu mạch.
     *
     * @throws ScriptWriterException
     */
    public static function forScene(TopicShortScene $scene): string
    {
        $short = $scene->topicShort;
        $characters = collect($short->characters ?? [])->filter(fn ($c) => in_array($c['name'], $scene->characters ?? [], true))->values()
            ->map(fn ($c) => "- {$c['name']}: ".($c['look'] ?? ''))->implode("\n");
        $scenes = $short->scenes()->get(['id', 'position', 'narration'])->keyBy('position');
        $before = $scenes->get($scene->position - 1)?->narration;
        $after = $scenes->get($scene->position + 1)?->narration;
        $medium = ['manga' => 'black-and-white manga', 'manga_color' => 'full-color manga', 'anime' => 'full-color TV anime'][$short->image_style] ?? 'anime';
        $text = "Topic: {$short->topic}\nArt: ".($short->art_style ?: 'anime')."\nPicture type: {$medium} (do not mention any other color treatment)\n\nCharacters in this picture:\n".($characters ?: '(choose from the line)')
            ."\n\nPrevious line: ".($before ?? '(none)')
            ."\nTHIS SCENE'S LINE: {$scene->narration}"
            ."\nOn-screen text: ".($scene->on_screen_text ?: '(none)')
            ."\nMood: ".($scene->mood ?: 'unknown')
            ."\nNext line: ".($after ?? '(none)')
            .(filled($scene->image_prompt) ? "\n\nOld prompt (may not match the line any more): {$scene->image_prompt}" : '');

        $data = AiUsage::withContext(['user_id' => $short->user_id, 'subject' => "topic_short:{$short->id}"], fn () => FilmClaude::json(
            self::SCENE_SYSTEM,
            $text,
            ['type' => 'object', 'additionalProperties' => false, 'required' => ['image_prompt'], 'properties' => ['image_prompt' => ['type' => 'string']]],
            'topic_short',
            "topic_short_scene:{$scene->id}",
            1000,
            fn () => ['image_prompt' => trim(implode(', ', $scene->characters ?? []).' — '.$scene->narration)],
        ));
        $prompt = trim((string) ($data['image_prompt'] ?? ''));
        if (mb_strlen($prompt) < 15) {
            throw new ScriptWriterException('Claude không trả prompt mới.');
        }

        return mb_substr($prompt, 0, 2000);
    }

    /** @throws ScriptWriterException */
    public static function rewrite(TopicShortScene $scene, string $refusal): string
    {
        $short = $scene->topicShort;
        $characters = collect($short->characters ?? [])->filter(fn ($c) => in_array($c['name'], $scene->characters ?? [], true))->values()
            ->map(fn ($c) => "- {$c['name']}: ".($c['look'] ?? ''))->implode("\n");
        $text = "Characters:\n".($characters ?: '(none)')."\n\nNarration line this picture illustrates: {$scene->narration}\n\nOld prompt:\n{$scene->image_prompt}\n\nThe image AI refused it and said:\n".($refusal ?: '(no explanation)');

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
    An image AI refused to draw a black-and-white manga picture of a character with a given facial expression or fighting action; its explanation is given.
    The character's name, form (e.g. Super Saiyan), look (hair, face, eyes, build, outfit, marks) and the framing stay EXACTLY as they are: the picture must still look like the same character as the other portraits in the set. You only rewrite the expression / action line.
    - Expression: write a short, gentle facial expression (under 25 words) close to the requested one: no anger, screaming, pain, fear, sweat drops.
    - Fighting action or signature technique: keep the move and its name, shown as energy and motion only (glowing beams, aura, light flash, shockwave, motion lines); no blood, injuries, pain faces, hitting a person or gripping. Under 35 words.
    - When the AI suggests a calmer variant or removing an effect, use its suggestion.
    - English, expression / action only: do not describe the character, the style or the background.
    PROMPT;

    /** Prompt mới cho chân dung nhân vật bị từ chối: giữ nguyên ngoại hình và khung hình, Claude chỉ viết lại biểu cảm. */
    public static function rewritePortrait(CharacterPortrait $portrait, string $refusal): string
    {
        [, $look] = DragonBall::CHARACTERS[$portrait->character];
        $expression = DragonBall::pose($portrait->character, $portrait->expression)[1] ?? '';
        $text = 'Character look (kept as is): '.$look."\nRequested expression / action: {$expression}"
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

        return DragonBall::prompt($portrait->character, $portrait->expression, poseText: $softer);
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
