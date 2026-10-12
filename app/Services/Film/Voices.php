<?php

namespace App\Services\Film;

use App\Models\Series;
use App\Models\SeriesCharacter;

/**
 * Giọng TTS (Kokoro-82M, giấy phép Apache-2.0). Mỗi loại giọng có vài giọng Kokoro; mỗi nhân vật trong một bộ phim được một giọng
 * riêng (không trùng nhân vật khác, không trùng người dẫn chuyện) và giữ cố định ở mọi shot, mọi tập: cùng giọng + cùng thông số
 * thì Kokoro luôn đọc ra cùng một chất giọng. pitch > 1 nâng cao độ cho giọng trẻ em / sinh vật.
 */
final class Voices
{
    public const TYPES = [
        'kid_boy' => ['label' => 'Bé trai', 'voice' => 'am_puck', 'pitch' => 1.18, 'speed' => 1.0],
        'kid_girl' => ['label' => 'Bé gái', 'voice' => 'af_bella', 'pitch' => 1.12, 'speed' => 1.0],
        'adult_male' => ['label' => 'Nam người lớn', 'voice' => 'am_michael', 'pitch' => 1.0, 'speed' => 1.0],
        'adult_female' => ['label' => 'Nữ người lớn', 'voice' => 'af_sarah', 'pitch' => 1.0, 'speed' => 1.0],
        'elder' => ['label' => 'Ông / người già nam', 'voice' => 'bm_fable', 'pitch' => 0.94, 'speed' => 0.92],
        'elder_female' => ['label' => 'Bà / người già nữ', 'voice' => 'bf_alice', 'pitch' => 0.95, 'speed' => 0.92],
        'creature' => ['label' => 'Thú / sinh vật', 'voice' => 'af_kore', 'pitch' => 1.28, 'speed' => 1.05],
    ];

    /** Các giọng của từng loại, theo thứ tự ưu tiên (giọng đầu là mặc định ở TYPES). [voice, pitch, speed, mô tả]. */
    public const POOLS = [
        'kid_boy' => [['am_puck', 1.18, 1.0, 'lanh lợi'], ['am_adam', 1.25, 1.05, 'trong, cao'], ['am_echo', 1.22, 1.0, 'hiền'], ['am_liam', 1.2, 1.05, 'sôi nổi']],
        'kid_girl' => [['af_bella', 1.12, 1.0, 'tươi'], ['af_nicole', 1.12, 1.0, 'nhẹ nhàng'], ['bf_emma', 1.12, 1.05, 'giọng Anh'], ['af_sky', 1.14, 1.0, 'trong'], ['af_jessica', 1.12, 1.05, 'nhanh nhảu']],
        'adult_male' => [['am_michael', 1.0, 1.0, 'ấm'], ['am_onyx', 1.0, 0.98, 'trầm'], ['am_fenrir', 1.0, 1.0, 'khoẻ'], ['bm_lewis', 1.0, 1.0, 'giọng Anh']],
        'adult_female' => [['af_sarah', 1.0, 1.0, 'dịu'], ['af_nova', 1.0, 1.0, 'rõ ràng'], ['bf_isabella', 1.0, 1.0, 'giọng Anh'], ['af_river', 1.0, 1.0, 'trẻ']],
        'elder' => [['bm_fable', 0.94, 0.92, 'hiền từ'], ['bm_daniel', 0.92, 0.92, 'trầm ấm']],
        'elder_female' => [['bf_alice', 0.95, 0.92, 'hiền'], ['af_aoede', 0.94, 0.92, 'ấm']],
        'creature' => [['af_kore', 1.28, 1.05, 'líu lo'], ['bf_lily', 1.3, 1.08, 'nhỏ, cao'], ['am_eric', 1.32, 1.05, 'tinh nghịch'], ['am_santa', 0.9, 0.95, 'ồm, to lớn']],
    ];

    /** Cách đọc lời thoại của tập. */
    public const ENGINES = [
        'kokoro' => 'Nhanh (Kokoro)',
        'expressive' => 'Biểu cảm (Chatterbox, chậm)',
    ];

    /**
     * Ngữ điệu theo cảm xúc của câu (cảm xúc do Claude ghi khi viết shot):
     * Kokoro: [nhân tốc độ, nhân cao độ]; Chatterbox: [độ biểu cảm, cfg] (cfg thấp hơn khi biểu cảm mạnh để nhịp tự nhiên).
     */
    public const EMOTIONS = [
        'excited' => ['kokoro' => [1.12, 1.05], 'expressive' => [0.85, 0.35]],
        'surprised' => ['kokoro' => [1.08, 1.06], 'expressive' => [0.8, 0.35]],
        'laughing' => ['kokoro' => [1.1, 1.06], 'expressive' => [0.9, 0.35]],
        'scared' => ['kokoro' => [1.06, 1.04], 'expressive' => [0.8, 0.4]],
        'angry' => ['kokoro' => [1.05, 0.97], 'expressive' => [0.8, 0.4]],
        'happy' => ['kokoro' => [1.05, 1.03], 'expressive' => [0.65, 0.45]],
        'proud' => ['kokoro' => [1.0, 1.0], 'expressive' => [0.6, 0.5]],
        'worried' => ['kokoro' => [0.92, 0.98], 'expressive' => [0.6, 0.5]],
        'sad' => ['kokoro' => [0.88, 0.96], 'expressive' => [0.6, 0.5]],
        'curious' => ['kokoro' => [0.97, 1.02], 'expressive' => [0.55, 0.5]],
        'thinking' => ['kokoro' => [0.9, 1.0], 'expressive' => [0.45, 0.5]],
        'calm' => ['kokoro' => [0.93, 0.99], 'expressive' => [0.4, 0.5]],
    ];

    /** Cảm xúc lạ / không ghi: đọc trung tính. */
    public const NEUTRAL = ['kokoro' => [1.0, 1.0], 'expressive' => [0.5, 0.5]];

    /** @return array{0: float, 1: float} */
    public static function emotion(?string $emotion, string $engine): array
    {
        return (self::EMOTIONS[strtolower(trim((string) $emotion))] ?? self::NEUTRAL)[$engine] ?? self::NEUTRAL['kokoro'];
    }

    /** Câu mẫu (khoảng 8 giây, nhiều sắc thái) đọc bằng Kokoro làm giọng mẫu cho Chatterbox bắt chước. */
    public const REFERENCE_TEXT = 'Hello there! I love going on adventures with my friends. Sometimes things get a little bit scary, '
        .'and sometimes they are really, really funny. But we always find the answer together!';

    /** Giọng dẫn chuyện của bộ phim (không nhân vật nào dùng trùng). */
    public const NARRATOR = ['type' => 'narrator', 'voice' => 'bm_george', 'pitch' => 1.0, 'speed' => 0.95];

    /**
     * Giọng cho một loại: giọng đầu tiên trong nhóm chưa có ai dùng (hết thì quay lại giọng đầu, đổi nhẹ cao độ để vẫn khác).
     *
     * @param  list<string>  $used  Giọng Kokoro đã dùng trong bộ phim.
     * @return array{type: string, voice: string, pitch: float, speed: float}
     */
    public static function forType(string $type, array $used = []): array
    {
        $type = isset(self::POOLS[$type]) ? $type : 'adult_female';
        $pool = self::POOLS[$type];
        $free = collect($pool)->first(fn (array $option) => ! in_array($option[0], $used, true));
        [$voice, $pitch, $speed] = $free ?? $pool[0];
        if ($free === null) {
            $pitch = round($pitch + 0.06 * (count(array_keys($used, $voice, true)) % 3 + 1), 2);
        }

        return compact('type', 'voice', 'pitch', 'speed');
    }

    /** Giọng mới cho nhân vật của bộ phim, tránh giọng của các nhân vật khác. */
    public static function assign(Series $series, string $type, ?SeriesCharacter $except = null): array
    {
        return self::forType($type, self::used($series, $except));
    }

    /** @return list<string> Giọng Kokoro đang dùng trong bộ phim (kể cả người dẫn chuyện). */
    public static function used(Series $series, ?SeriesCharacter $except = null): array
    {
        return $series->characters()->when($except, fn ($q) => $q->whereKeyNot($except->id))->get(['voice'])
            ->pluck('voice.voice')->filter()->push(self::NARRATOR['voice'])->values()->all();
    }

    /** Chọn đúng một giọng trong danh sách (người dùng đổi giọng). */
    public static function option(string $voice): ?array
    {
        foreach (self::POOLS as $type => $pool) {
            foreach ($pool as [$id, $pitch, $speed]) {
                if ($id === $voice) {
                    return compact('type', 'voice', 'pitch', 'speed');
                }
            }
        }

        return null;
    }

    /** @return list<array{value: string, type: string, label: string}> Mọi giọng, để chọn trên trang nhân vật. */
    public static function options(): array
    {
        return collect(self::POOLS)->flatMap(fn (array $pool, string $type) => collect($pool)->map(fn (array $option, int $i) => [
            'value' => $option[0],
            'type' => $type,
            'label' => self::TYPES[$type]['label'].' '.($i + 1).' – '.$option[3],
        ]))->values()->all();
    }

    /** Mọi giọng Kokoro app dùng (tải sẵn vào dịch vụ audio: docker/audio/kokoro_voices.txt). @return list<string> */
    public static function all(): array
    {
        return collect(self::POOLS)->flatten(1)->pluck(0)->push(self::NARRATOR['voice'])->unique()->values()->all();
    }

    /** Câu nghe thử giọng nhân vật. */
    public static function sample(SeriesCharacter $character): string
    {
        return "Hi! I'm {$character->name}. Come on, let's go on an adventure together!";
    }
}
