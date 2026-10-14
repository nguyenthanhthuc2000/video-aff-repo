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

    /** Tên tiếng Anh của chuyển động máy (gửi Muse). */
    public const MOVES = [
        'static' => 'locked-off camera, no camera movement', 'push_in' => 'camera slowly pushes in', 'pull_out' => 'camera slowly pulls back',
        'pan_left' => 'camera slowly pans left', 'pan_right' => 'camera slowly pans right', 'tilt_up' => 'camera slowly tilts up', 'tilt_down' => 'camera slowly tilts down',
    ];

    public static function build(Shot $shot, ?string $notes = null): string
    {
        if ($shot->episode->series->silent()) {
            return self::short($shot, $notes);
        }
        if ($shot->episode->series->musicVideo()) {
            return self::musicVideo($shot, $notes);
        }
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

        return "Make a {$seconds}-second clip ({$shot->episode->series->frameLine()}). Camera: {$shot->camera}; keep the framing of the keyframe, only gentle camera motion.\n"
            .'What happens: '.($shot->motion ?: $shot->action)."\n"
            .($inFrame !== '' ? "Characters (keep them exactly as drawn): {$inFrame}.\n" : '')
            .($timeline !== ''
                ? "The attached audio is the dialogue track of this clip; make the clip exactly as long as the audio. Dialogue timeline:\n{$timeline}\n"
                    .'Characters who are not speaking listen and react with small natural expressions, mouths closed. Do not add any other voice, music or sound.'."\n"
                : "No one speaks in this clip: mouths stay closed. No voices, no music.\n")
            .(filled($notes) ? 'Additional direction: '.trim($notes).".\n" : '')
            .'Smooth, simple TV-cartoon animation (like a 2D kids series), natural timing, no sudden cuts, no new characters.';
    }

    /**
     * MV ca nhạc: shot hát nhép gửi kèm đúng đoạn bài hát của shot để người hát khớp môi theo; shot khác không ai hát.
     * Tiếng trong clip bị bỏ, MV dùng bài hát gốc.
     */
    private static function musicVideo(Shot $shot, ?string $notes): string
    {
        $series = $shot->episode->series;
        $people = ShotPrompt::characters($shot);
        $inFrame = $people->map(fn (SeriesCharacter $c) => $c->name.' ('.Str::words($c->appearance, 14, '').')')->implode('; ');
        $singer = $people->first()?->name ?? 'the singer';

        return 'Make a '.self::seconds($shot)."-second music video clip from the attached first frame ({$series->frameLine()}).\n"
            .($shot->motion ?: $shot->action)."\n"
            ."Camera: {$shot->camera}; ".(self::MOVES[$shot->camera_move ?? ''] ?? self::MOVES['push_in']).".\n"
            .($inFrame !== '' ? "People (keep them exactly as in the frame): {$inFrame}.\n" : '')
            .(($continuity = ShotPrompt::continuity($shot)) !== '' ? "Continuity (must stay true for the whole clip):\n{$continuity}\n" : '')
            .($shot->lip_sync && filled($shot->lyric)
                ? "The attached audio is the song during this clip. {$singer} sings it: lips, jaw and breathing in exact sync with the vocals, "
                    ."natural singing expressions, the clip is exactly as long as the audio. Lyric: \"{$shot->lyric}\". Nobody else sings.\n"
                : "Nobody sings or talks in this clip: mouths stay closed or relaxed. No voices.\n")
            .(filled($notes) ? 'Additional direction: '.trim($notes).".\n" : '')
            ."Visual style: {$series->style}. One continuous shot with music video energy (movement in hair, cloth, light and camera), "
            .'no cuts, no morphing faces or outfits, no new people, no text.';
    }

    /** Truyện ngắn không thoại: prompt image-to-video theo chuyển động Claude viết cho shot, một hành động chính, không lời. */
    private static function short(Shot $shot, ?string $notes): string
    {
        $inFrame = ShotPrompt::characters($shot)->map(fn (SeriesCharacter $c) => $c->name.' ('.Str::words($c->appearance, 14, '').')')->implode('; ');

        return 'Animate the supplied reference frame for '.self::seconds($shot)." seconds ({$shot->episode->series->frameLine()}).\n"
            .($shot->motion ?: $shot->action)."\n"
            ."Camera: {$shot->camera}; ".(self::MOVES[$shot->camera_move ?? ''] ?? self::MOVES['push_in']).".\n"
            .($inFrame !== '' ? "Characters (keep them exactly as in the frame): {$inFrame}.\n" : '')
            .(($continuity = ShotPrompt::continuity($shot)) !== '' ? "Continuity (must stay true for the whole clip):\n{$continuity}\n" : '')
            .(filled($notes) ? 'Additional direction: '.trim($notes).".\n" : '')
            .'Maintain exact identity, fur pattern, body proportions, clothing, accessories, background and prop positions; keep key props visible. '
            .'One continuous shot, no cuts, no transformation, no morphing props, no new characters, natural character motion. '
            .'No dialogue: mouths stay closed (silent laughs and expressions are fine); no voices, no music, no text.';
    }
}
