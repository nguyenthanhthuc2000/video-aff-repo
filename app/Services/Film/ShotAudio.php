<?php

namespace App\Services\Film;

use App\Models\SeriesCharacter;
use App\Models\Shot;
use App\Services\Audio\ExpressiveTts;
use App\Services\Audio\Tts;
use App\Support\Media;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Tiếng thoại của shot: mỗi câu đọc bằng giọng cố định của nhân vật nói câu đó (người dẫn chuyện có giọng riêng), nên cùng một
 * nhân vật luôn cùng một giọng ở mọi shot, mọi tập. Ngữ điệu theo cảm xúc từng câu. Hai cách đọc (theo tập):
 * kokoro (nhanh, chỉnh tốc độ / cao độ theo cảm xúc) hoặc expressive (Chatterbox bắt chước giọng mẫu Kokoro của nhân vật, biểu cảm hơn).
 * Khoá = lời + giọng + cảm xúc + cách đọc: đổi bất kỳ cái nào thì đọc lại.
 */
final class ShotAudio
{
    /** Khoảng lặng trước câu đầu, giữa các câu, sau câu cuối (giây). */
    public const LEAD = 0.3;

    public const GAP = 0.35;

    public const TAIL = 0.4;

    /** Đánh dấu shot đang chờ / đang đọc lời (để trang tập hiện tiến độ); tự hết hạn nếu job bị mất. */
    public static function markPending(Shot $shot): void
    {
        self::setError($shot, null);
        // Đọc biểu cảm cả tập có thể mất vài giờ (các shot xếp hàng lần lượt).
        Cache::put(self::pendingKey($shot), true, now()->addHours(8));
    }

    public static function clearPending(Shot $shot): void
    {
        Cache::forget(self::pendingKey($shot));
    }

    public static function isPending(Shot $shot): bool
    {
        return (bool) Cache::get(self::pendingKey($shot));
    }

    /** Lỗi đọc lời gần nhất (hiện trên thẻ shot), xoá khi đọc lại. */
    public static function setError(Shot $shot, ?string $message): void
    {
        $message === null ? Cache::forget("shot-audio-error:{$shot->id}") : Cache::put("shot-audio-error:{$shot->id}", mb_substr($message, 0, 300), now()->addDay());
    }

    public static function error(Shot $shot): ?string
    {
        return Cache::get("shot-audio-error:{$shot->id}");
    }

    private static function pendingKey(Shot $shot): string
    {
        return "shot-audio-pending:{$shot->id}";
    }

    public static function engine(Shot $shot): string
    {
        $engine = $shot->episode->voice_engine ?? 'kokoro';

        return isset(Voices::ENGINES[$engine]) ? $engine : 'kokoro';
    }

    /**
     * Từng câu: giọng của người nói, ngữ điệu theo cảm xúc. Kokoro: tốc độ / cao độ đã nhân hệ số cảm xúc;
     * expressive: thêm độ biểu cảm, cfg và giọng mẫu (voice/pitch/speed gốc của nhân vật).
     *
     * @return list<array{text: string, voice: string, pitch: float, speed: float, emotion: string, character_id: ?int, exaggeration?: float, cfg_weight?: float}>
     */
    public static function lines(Shot $shot, ?Collection $characters = null, ?string $engine = null): array
    {
        $characters ??= $shot->episode->series->characters()->get()->keyBy('id');
        $engine ??= self::engine($shot);

        return collect($shot->dialogue ?? [])->filter(fn ($d) => trim((string) ($d['line'] ?? '')) !== '')->map(function ($d) use ($characters, $engine) {
            /** @var ?SeriesCharacter $speaker */
            $speaker = isset($d['character_id']) ? $characters->get($d['character_id']) : null;
            $voice = filled($speaker?->voice['voice'] ?? null) ? $speaker->voice : Voices::NARRATOR;
            $emotion = strtolower(trim((string) ($d['emotion'] ?? '')));
            [$a, $b] = Voices::emotion($emotion, $engine);
            $line = [
                'text' => trim((string) $d['line']),
                'voice' => (string) $voice['voice'],
                'pitch' => (float) ($voice['pitch'] ?? 1.0),
                'speed' => (float) ($voice['speed'] ?? 1.0),
                'emotion' => $emotion,
                'character_id' => $speaker?->id,
            ];

            return $engine === 'expressive'
                ? [...$line, 'exaggeration' => $a, 'cfg_weight' => $b]
                : [...$line, 'speed' => round($line['speed'] * $a, 3), 'pitch' => round($line['pitch'] * $b, 3)];
        })->values()->all();
    }

    /** Khoá nội dung tiếng hiện tại; null nếu shot không có lời. */
    public static function key(Shot $shot, ?Collection $characters = null): ?string
    {
        $engine = self::engine($shot);
        $lines = self::lines($shot, $characters, $engine);

        return $lines === [] ? null : sha1(json_encode([$engine, $lines]));
    }

    /** Tiếng đã đọc còn đúng với lời và giọng hiện tại (shot không lời thì luôn đúng). */
    public static function isCurrent(Shot $shot, ?Collection $characters = null): bool
    {
        return $shot->audio_key === self::key($shot, $characters) && ($shot->audio_key === null || $shot->audio_path !== null);
    }

    /**
     * Đọc lời thoại một bước (Kokoro xong ngay; biểu cảm thì gửi / hỏi dịch vụ, gọi lại tới khi advance() trả true).
     *
     * @throws RuntimeException
     */
    public static function ensure(Shot $shot, ?Tts $tts = null): void
    {
        self::advance($shot, $tts);
    }

    /**
     * Tiến thêm một bước đọc lời thoại; true khi tiếng đã đúng với lời / giọng / cách đọc hiện tại.
     * Kokoro đọc ngay. Biểu cảm: lần đầu gửi dịch vụ Chatterbox (kèm giọng mẫu từng nhân vật), các lần sau hỏi kết quả.
     * Không tốn Credits (chạy trên máy).
     *
     * @throws RuntimeException
     */
    public static function advance(Shot $shot, ?Tts $tts = null, ?ExpressiveTts $expressive = null): bool
    {
        if (self::isCurrent($shot)) {
            return true;
        }
        $lines = self::lines($shot);
        if ($lines === []) {
            $old = $shot->audio_path;
            $shot->update(['audio_path' => null, 'audio_key' => null, 'audio_ms' => null, 'audio_lines' => null]);
            Media::delete($old);

            return true;
        }
        $key = (string) self::key($shot);

        if (self::engine($shot) === 'kokoro') {
            $result = ($tts ?? app(Tts::class))->speak(
                array_map(fn ($l) => collect($l)->only(['text', 'voice', 'pitch', 'speed'])->all(), $lines),
                self::LEAD, self::GAP, self::TAIL,
            );
            self::store($shot, $lines, $result, $key);

            return true;
        }

        $expressive ??= app(ExpressiveTts::class);
        $taskKey = "expressive-task:{$shot->id}:{$key}";
        if ($task = Cache::get($taskKey)) {
            $result = $expressive->poll($task);
            if ($result === null) {
                return false;
            }
            Cache::forget($taskKey);
            if ($result !== false) {
                self::store($shot, $lines, $result, $key);

                return true;
            }
            // Dịch vụ đã khởi động lại, mất yêu cầu: gửi lại bên dưới.
        }
        $seriesId = $shot->episode->series_id;
        $payload = array_map(fn ($l) => [
            'text' => $l['text'],
            'ref' => (string) Media::disk()->get(VoiceReference::ensure($seriesId, $l, $tts)),
            'exaggeration' => $l['exaggeration'],
            'cfg_weight' => $l['cfg_weight'],
        ], $lines);
        Cache::put($taskKey, $expressive->start($payload, self::LEAD, self::GAP, self::TAIL), now()->addHours(8));

        return false;
    }

    /** Lưu tiếng đã đọc; lời dài hơn shot thì kéo dài shot cho đủ đọc. */
    private static function store(Shot $shot, array $lines, array $result, string $key): void
    {
        $old = $shot->audio_path;
        $episode = $shot->episode;
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
