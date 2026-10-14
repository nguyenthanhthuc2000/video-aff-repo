<?php

namespace App\Services\English;

use App\Models\EnglishLesson;
use App\Services\Audio\Tts;
use App\Support\Media;
use RuntimeException;

/** Giọng mẫu đọc câu (Kokoro, giọng Mỹ rõ, chậm hơn một chút cho người học); miễn phí, chạy trên máy. */
final class ModelVoice
{
    public const VOICE = 'af_sarah';

    public const SPEED = 0.9;

    /**
     * Đọc từng câu, lưu file riêng; trả [{path, ms}] theo thứ tự câu.
     *
     * @param  list<string>  $texts
     * @return list<array{path: string, ms: int}>
     *
     * @throws RuntimeException
     */
    public static function speak(EnglishLesson $lesson, array $texts, string $prefix, ?Tts $tts = null): array
    {
        $tts ??= app(Tts::class);

        return array_map(function (string $text, int $i) use ($lesson, $prefix, $tts) {
            $result = $tts->speak([['text' => $text, 'voice' => self::VOICE, 'pitch' => 1.0, 'speed' => self::SPEED]], 0.05, 0, 0.1);
            $path = "english/{$lesson->user_id}/day-{$lesson->day}/{$prefix}-{$i}-".bin2hex(random_bytes(3)).'.wav';
            Media::disk()->put($path, $result['contents']);

            return ['path' => $path, 'ms' => $result['duration_ms']];
        }, $texts, array_keys($texts));
    }

    /** Xoá các file giọng mẫu cũ. @param list<array{path: string}>|null $audio */
    public static function forget(?array $audio): void
    {
        foreach ($audio ?? [] as $item) {
            Media::delete($item['path'] ?? null);
        }
    }
}
