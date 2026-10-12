<?php

namespace App\Services\Film;

use App\Models\SeriesCharacter;
use App\Models\Shot;
use App\Services\Audio\Tts;
use App\Support\Media;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Tiếng thoại của shot: mỗi câu đọc bằng giọng cố định của nhân vật nói câu đó (người dẫn chuyện có giọng riêng), nên cùng một
 * nhân vật luôn cùng một giọng ở mọi shot, mọi tập. Khoá = lời + giọng: đổi lời hoặc đổi giọng nhân vật thì đọc lại.
 */
final class ShotAudio
{
    /** Khoảng lặng trước câu đầu, giữa các câu, sau câu cuối (giây). */
    public const LEAD = 0.3;

    public const GAP = 0.35;

    public const TAIL = 0.4;

    /** @return list<array{text: string, voice: string, pitch: float, speed: float, character_id: ?int}> */
    public static function lines(Shot $shot, ?Collection $characters = null): array
    {
        $characters ??= $shot->episode->series->characters()->get()->keyBy('id');

        return collect($shot->dialogue ?? [])->filter(fn ($d) => trim((string) ($d['line'] ?? '')) !== '')->map(function ($d) use ($characters) {
            /** @var ?SeriesCharacter $speaker */
            $speaker = isset($d['character_id']) ? $characters->get($d['character_id']) : null;
            $voice = filled($speaker?->voice['voice'] ?? null) ? $speaker->voice : Voices::NARRATOR;

            return [
                'text' => trim((string) $d['line']),
                'voice' => (string) $voice['voice'],
                'pitch' => (float) ($voice['pitch'] ?? 1.0),
                'speed' => (float) ($voice['speed'] ?? 1.0),
                'character_id' => $speaker?->id,
            ];
        })->values()->all();
    }

    /** Khoá nội dung tiếng hiện tại; null nếu shot không có lời. */
    public static function key(Shot $shot, ?Collection $characters = null): ?string
    {
        $lines = self::lines($shot, $characters);

        return $lines === [] ? null : sha1(json_encode($lines));
    }

    /** Tiếng đã đọc còn đúng với lời và giọng hiện tại (shot không lời thì luôn đúng). */
    public static function isCurrent(Shot $shot, ?Collection $characters = null): bool
    {
        return $shot->audio_key === self::key($shot, $characters) && ($shot->audio_key === null || $shot->audio_path !== null);
    }

    /**
     * Đọc lời thoại nếu chưa có hoặc đã cũ; kéo dài shot nếu lời dài hơn shot. Không tốn Credits (Kokoro chạy trên máy).
     *
     * @throws RuntimeException
     */
    public static function ensure(Shot $shot, ?Tts $tts = null): void
    {
        if (self::isCurrent($shot)) {
            return;
        }
        $lines = self::lines($shot);
        $old = $shot->audio_path;
        if ($lines === []) {
            $shot->update(['audio_path' => null, 'audio_key' => null, 'audio_ms' => null, 'audio_lines' => null]);
            Media::delete($old);

            return;
        }

        $result = ($tts ?? app(Tts::class))->speak(
            array_map(fn ($l) => collect($l)->except('character_id')->all(), $lines),
            self::LEAD, self::GAP, self::TAIL,
        );
        $episode = $shot->episode;
        $key = self::key($shot);
        $path = "series/{$episode->series_id}/episodes/{$episode->id}/shot-{$shot->id}-voice-".substr((string) $key, 0, 8).'.wav';
        Media::disk()->put($path, $result['contents']);

        $timeline = array_map(fn ($line, $time) => [...$time, 'character_id' => $line['character_id']], $lines, $result['lines']);
        $shot->update([
            'audio_path' => $path,
            'audio_key' => $key,
            'audio_ms' => $result['duration_ms'],
            'audio_lines' => $timeline,
            // Lời dài hơn shot: kéo dài shot cho đủ đọc (làm tròn 0,1 giây).
            'duration_ms' => max($shot->duration_ms, (int) (ceil($result['duration_ms'] / 100) * 100)),
        ]);
        $episode->update(['duration_ms' => (int) $episode->shots()->sum('duration_ms')]);
        if ($old !== $path) {
            Media::delete($old);
        }
    }
}
