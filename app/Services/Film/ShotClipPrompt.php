<?php

namespace App\Services\Film;

use App\Models\SeriesCharacter;
use App\Models\Shot;
use Illuminate\Support\Str;

/** Mô tả chuyển động gửi Muse để biến khung hình của shot thành clip, kèm dòng thời gian lời thoại để khớp môi. */
final class ShotClipPrompt
{
    /** Muse làm clip tối đa khoảng 10 giây; shot dài hơn thì giữ khung cuối cho đủ. */
    public const MAX_SECONDS = 10;

    public static function seconds(Shot $shot): int
    {
        return max(3, min(self::MAX_SECONDS, (int) ceil($shot->duration_ms / 1000)));
    }

    public static function build(Shot $shot, ?string $notes = null): string
    {
        $characters = $shot->episode->series->characters()->get()->keyBy('id');
        $inFrame = ShotPrompt::characters($shot)->map(fn (SeriesCharacter $c) => $c->name.' ('.Str::words($c->appearance, 12, '').')')->implode('; ');
        $seconds = self::seconds($shot);

        $timeline = collect($shot->audio_lines ?? [])->map(function (array $line, int $i) use ($shot, $characters) {
            $text = collect($shot->dialogue ?? [])->filter(fn ($d) => trim((string) ($d['line'] ?? '')) !== '')->values()[$i]['line'] ?? '';
            $speaker = isset($line['character_id']) ? $characters->get($line['character_id'])?->name : null;
            $from = number_format($line['start_ms'] / 1000, 1);
            $to = number_format($line['end_ms'] / 1000, 1);

            return $speaker
                ? "{$from}s–{$to}s: {$speaker} speaks this line (only {$speaker}'s mouth moves, lips in sync with the audio): \"{$text}\""
                : "{$from}s–{$to}s: off-screen narrator says: \"{$text}\" (no character's mouth moves)";
        })->implode("\n");

        return "Make a {$seconds}-second clip. Camera: {$shot->camera}; keep the framing of the keyframe, only gentle camera motion.\n"
            ."What happens: {$shot->action}\n"
            .($inFrame !== '' ? "Characters (keep them exactly as drawn): {$inFrame}.\n" : '')
            .($timeline !== ''
                ? "The attached audio is the dialogue track of this clip; make the clip exactly as long as the audio. Dialogue timeline:\n{$timeline}\n"
                    .'Characters who are not speaking listen and react with small natural expressions, mouths closed. Do not add any other voice, music or sound.'."\n"
                : "No one speaks in this clip: mouths stay closed. No voices, no music.\n")
            .(filled($notes) ? 'Additional direction: '.trim($notes).".\n" : '')
            .'Smooth, simple TV-cartoon animation (like a 2D kids series), natural timing, no sudden cuts, no new characters.';
    }
}
