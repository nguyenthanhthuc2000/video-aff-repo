<?php

namespace App\Services\Film;

/**
 * Giọng TTS mặc định (Kokoro-82M, giấy phép Apache-2.0) theo loại giọng; pitch > 1 nâng cao độ cho giọng trẻ em.
 * Tạm theo các mẫu thử F0; người dùng đổi được từng nhân vật.
 */
final class Voices
{
    public const TYPES = [
        'kid_boy' => ['label' => 'Bé trai', 'voice' => 'am_puck', 'pitch' => 1.18, 'speed' => 1.0],
        'kid_girl' => ['label' => 'Bé gái', 'voice' => 'af_bella', 'pitch' => 1.12, 'speed' => 1.0],
        'adult_male' => ['label' => 'Nam người lớn', 'voice' => 'am_michael', 'pitch' => 1.0, 'speed' => 1.0],
        'adult_female' => ['label' => 'Nữ người lớn', 'voice' => 'af_sarah', 'pitch' => 1.0, 'speed' => 1.0],
        'elder' => ['label' => 'Người già', 'voice' => 'bm_george', 'pitch' => 0.94, 'speed' => 0.92],
        'creature' => ['label' => 'Thú / sinh vật', 'voice' => 'am_puck', 'pitch' => 1.3, 'speed' => 1.05],
    ];

    /** Giọng dẫn chuyện của bộ phim. */
    public const NARRATOR = ['type' => 'narrator', 'voice' => 'bm_george', 'pitch' => 1.0, 'speed' => 0.95];

    /** @return array{type: string, voice: string, pitch: float, speed: float} */
    public static function forType(string $type): array
    {
        $type = isset(self::TYPES[$type]) ? $type : 'adult_female';
        ['voice' => $voice, 'pitch' => $pitch, 'speed' => $speed] = self::TYPES[$type];

        return compact('type', 'voice', 'pitch', 'speed');
    }
}
