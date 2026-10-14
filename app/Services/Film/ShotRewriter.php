<?php

namespace App\Services\Film;

use App\Models\Shot;
use App\Services\Image\MuseBrowserImageGenerator;
use App\Services\Script\ScriptWriterException;
use Illuminate\Support\Str;

/** Muse từ chối vẽ khung hình: Claude viết lại cảnh của shot (cùng ý truyện, bố cục khác, an toàn cho trẻ em) để vẽ lại. */
final class ShotRewriter
{
    public const SYSTEM = <<<'PROMPT'
    You fix storyboard shots of an English 2D animated kids series after the image AI refused to draw the keyframe.
    Rewrite the shot so it keeps the same story beat (same characters, same place, same meaning for the dialogue) but becomes a clearly
    NEW picture: different composition, framing and pose, described in fresh words (do not reuse the old sentence). The image AI may remember
    the refused request, so the new description must not look like a retry of it.
    Address the refusal reason if one is given. Keep it gentle and wholesome for young children: no danger, injury, fighting, scary or
    intense moments, no realistic weapons, no brand names, no readable text or signs, no lookalikes of famous characters.
    action: what we SEE in this one frame, concrete and drawable, plus one simple movement, naming the characters exactly. 1–2 sentences.
    camera: pick from the allowed list, preferably different from the old one.
    PROMPT;

    public const CLIP_SYSTEM = <<<'PROMPT'
    You fix one shot of an animated series after the image-to-video AI refused to animate its keyframe. The keyframe image stays the same;
    only the motion description changes. Write a NEW motion for this frame that keeps the same story beat but avoids whatever the refusal
    reason points at (follow its suggestion of a makeable version when it gives one). Use fresh wording, not a retry of the old sentence, because
    the video AI may remember the refused request. Keep it gentle and wholesome: no danger, injury, mess or objects flying at faces, no fighting,
    nothing scary, no brand names, no text. One simple, continuous main movement that starts from exactly what the keyframe shows, naming
    the characters exactly; small natural reactions are fine. 1–2 sentences in English.
    PROMPT;

    /** Lời giải thích của Muse trong lỗi "AI từ chối..." của shot; null nếu lỗi không phải bị từ chối. */
    public static function rejection(Shot $shot): ?string
    {
        return $shot->image_status === 'failed' ? self::reason((string) $shot->error) : null;
    }

    /** Muse từ chối làm clip của shot: lời giải thích; null nếu clip không bị từ chối. */
    public static function clipRejection(Shot $shot): ?string
    {
        return $shot->clip_status === 'failed' ? self::reason((string) $shot->clip_error) : null;
    }

    private static function reason(string $error): ?string
    {
        if (! (str_starts_with($error, MuseBrowserImageGenerator::REJECTED) || str_starts_with($error, MuseBrowserImageGenerator::REJECTED_LEGACY))) {
            return null;
        }

        return trim(Str::after($error, 'AI trả lời:'));
    }

    /**
     * Viết lại chuyển động của shot bị Muse từ chối làm clip (giữ khung hình).
     *
     * @throws ScriptWriterException
     */
    public static function rewriteMotion(Shot $shot, ?string $reason): string
    {
        $characters = ShotPrompt::characters($shot)->map(fn ($c) => "- {$c->name}: {$c->appearance}")->implode("\n");
        $text = "Series: {$shot->episode->series->title}\n"
            ."Episode {$shot->episode->number}: {$shot->episode->title}\n"
            .($shot->location ? "Location: {$shot->location->name}: {$shot->location->description}\n" : '')
            .($characters !== '' ? "Characters in the frame:\n{$characters}\n" : '')
            ."What the keyframe shows: {$shot->action}\n"
            .'Refused motion: '.($shot->motion ?: $shot->action)."\n"
            .(filled($reason) ? 'Refusal reason from the video AI: '.Str::limit($reason, 800)."\n" : '');

        $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['motion'], 'properties' => ['motion' => ['type' => 'string']]];
        $data = FilmClaude::json(self::CLIP_SYSTEM, $text, $schema, 'film_episode', "shot_clip_rewrite:{$shot->id}", 800, fn () => [
            'motion' => 'Gentle, calm movement from the pose in the frame: the characters breathe, blink and react softly.',
        ]);

        $motion = trim((string) ($data['motion'] ?? ''));
        if ($motion === '') {
            throw new ScriptWriterException('Claude không trả về chuyển động mới.');
        }

        return Str::limit($motion, 1000, '');
    }

    /**
     * @return array{action: string, camera: string}
     *
     * @throws ScriptWriterException
     */
    public static function rewrite(Shot $shot, ?string $reason): array
    {
        $characters = ShotPrompt::characters($shot)->map(fn ($c) => "- {$c->name}: {$c->appearance}")->implode("\n");
        $all = $shot->episode->series->characters()->get();
        $text = "Series: {$shot->episode->series->title}\n"
            ."Episode {$shot->episode->number}: {$shot->episode->title}\n"
            .($shot->location ? "Location: {$shot->location->name}: {$shot->location->description}\n" : '')
            .($characters !== '' ? "Characters in the frame:\n{$characters}\n" : '')
            ."Refused shot:\n".EpisodeWriter::describe($shot->only(['camera', 'action', 'dialogue']), $all)."\n"
            .(filled($reason) ? 'Refusal reason from the image AI: '.Str::limit($reason, 600)."\n" : '')
            .'Allowed camera values: '.implode(', ', EpisodeWriter::CAMERAS).'.';

        $schema = [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['action', 'camera'],
            'properties' => ['action' => ['type' => 'string'], 'camera' => ['type' => 'string', 'enum' => EpisodeWriter::CAMERAS]],
        ];
        $data = FilmClaude::json(self::SYSTEM, $text, $schema, 'film_episode', "shot_rewrite:{$shot->id}", 1500, fn () => [
            'action' => 'From a new angle: '.$shot->action,
            'camera' => collect(EpisodeWriter::CAMERAS)->first(fn ($camera) => $camera !== $shot->camera),
        ]);

        $action = trim((string) ($data['action'] ?? ''));
        if ($action === '') {
            throw new ScriptWriterException('Claude không trả về cảnh mới.');
        }

        return [
            'action' => Str::limit($action, 1500, ''),
            'camera' => in_array($data['camera'] ?? null, EpisodeWriter::CAMERAS, true) ? $data['camera'] : $shot->camera,
        ];
    }
}
