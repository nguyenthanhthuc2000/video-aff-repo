<?php

namespace App\Services\Film;

use App\Services\Audio\Tts;
use App\Support\Media;
use RuntimeException;

/**
 * Giọng mẫu của nhân vật cho Chatterbox: câu mẫu cố định đọc bằng giọng Kokoro của nhân vật. Cùng giọng → cùng file mẫu,
 * nên Chatterbox bắt chước đúng một giọng cho nhân vật ở mọi shot, mọi tập (đổi giọng nhân vật thì có mẫu mới).
 */
final class VoiceReference
{
    /** @param array{voice: string, pitch?: float, speed?: float} $voice */
    public static function path(int $seriesId, array $voice): string
    {
        return "series/{$seriesId}/voices/ref-{$voice['voice']}-".substr(sha1(json_encode([$voice['voice'], (float) ($voice['pitch'] ?? 1), (float) ($voice['speed'] ?? 1), Voices::REFERENCE_TEXT])), 0, 10).'.wav';
    }

    /**
     * Đường dẫn media của giọng mẫu (đọc bằng Kokoro nếu chưa có).
     *
     * @throws RuntimeException
     */
    public static function ensure(int $seriesId, array $voice, ?Tts $tts = null): string
    {
        $path = self::path($seriesId, $voice);
        if (! Media::disk()->exists($path)) {
            $result = ($tts ?? app(Tts::class))->speak([[
                'text' => Voices::REFERENCE_TEXT, 'voice' => $voice['voice'], 'pitch' => (float) ($voice['pitch'] ?? 1), 'speed' => (float) ($voice['speed'] ?? 1),
            ]], 0.1, 0.3, 0.2);
            Media::disk()->put($path, $result['contents']);
        }

        return $path;
    }
}
