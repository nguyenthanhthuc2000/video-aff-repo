<?php

namespace App\Support;

use App\Models\AppSetting;
use App\Models\Product;

/** Mẫu độ dài video (số cảnh, tổng số giây) và gợi ý mẫu hợp với sản phẩm. */
final class VideoPreset
{
    public const MIN_SCENES = 3;

    public const MAX_SCENES = 12;

    public const MIN_SECONDS = 10;

    public const MAX_SECONDS = 60;

    /** @var array<string, array{label: string, seconds: int, scenes: int, hint: string}> */
    public const PRESETS = [
        'short' => ['label' => 'Ngắn', 'seconds' => 15, 'scenes' => 4, 'hint' => 'Xem hết nhanh; hợp sản phẩm đơn giản, ít màu, ít size.'],
        'standard' => ['label' => 'Chuẩn', 'seconds' => 25, 'scenes' => 6, 'hint' => 'Cân bằng, hợp đa số sản phẩm thời trang.'],
        'detailed' => ['label' => 'Chi tiết', 'seconds' => 35, 'scenes' => 7, 'hint' => 'Nhiều màu, chất liệu cần cận cảnh, có nhiều đánh giá tốt.'],
        'long' => ['label' => 'Dài', 'seconds' => 50, 'scenes' => 9, 'hint' => 'Giá cao, nhiều cách phối, cần thuyết phục kỹ.'],
    ];

    /**
     * Gợi ý mẫu theo độ "nhiều chuyện để kể" của sản phẩm: số màu, đánh giá, số ảnh, giá.
     *
     * @return array{key: string, reason: string}
     */
    public static function recommend(Product $product): array
    {
        $reasons = [];
        $colors = preg_split('/[,;\/]|\bvà\b/u', (string) ($product->details['colors'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        if (count(array_filter(array_map('trim', $colors))) >= 3) {
            $reasons[] = 'nhiều màu';
        }
        if (mb_strlen((string) $product->reviews) >= 300) {
            $reasons[] = 'nhiều đánh giá';
        }
        if ($product->images()->count() >= 6) {
            $reasons[] = 'nhiều ảnh';
        }
        if (($product->price ?? 0) >= 300000) {
            $reasons[] = 'giá từ 300.000đ';
        }

        $key = match (count($reasons)) {
            0 => 'short',
            1, 2 => 'standard',
            3 => 'detailed',
            default => 'long',
        };

        return [
            'key' => $key,
            'reason' => $reasons === [] ? 'Sản phẩm ít thông tin, video ngắn dễ xem hết.' : 'Sản phẩm có '.implode(', ', $reasons).'.',
        ];
    }

    /** @return list<array{key: string, label: string, seconds: int, scenes: int, hint: string, recommended: bool}> */
    public static function options(Product $product): array
    {
        $recommended = self::recommend($product)['key'];

        return array_values(array_map(
            fn (string $key, array $preset) => ['key' => $key, ...$preset, 'recommended' => $key === $recommended],
            array_keys(self::PRESETS),
            self::PRESETS,
        ));
    }

    /**
     * Cài đặt chung (menu Cài đặt video): auto = đề xuất theo từng sản phẩm, fixed = cùng số cảnh / số giây cho mọi video.
     *
     * @return array{mode: 'auto'|'fixed', scene_count: int, target_seconds: int}
     */
    public static function settings(): array
    {
        $saved = json_decode((string) AppSetting::get(AppSetting::VIDEO_LENGTH), true) ?: [];
        $standard = self::PRESETS['standard'];

        return [
            'mode' => ($saved['mode'] ?? 'auto') === 'fixed' ? 'fixed' : 'auto',
            'scene_count' => (int) ($saved['scene_count'] ?? $standard['scenes']),
            'target_seconds' => (int) ($saved['target_seconds'] ?? $standard['seconds']),
        ];
    }

    /** @param  array{mode: string, scene_count: int, target_seconds: int}  $settings */
    public static function saveSettings(array $settings): void
    {
        AppSetting::put(AppSetting::VIDEO_LENGTH, json_encode([
            'mode' => $settings['mode'],
            'scene_count' => $settings['scene_count'],
            'target_seconds' => $settings['target_seconds'],
        ]));
    }

    /**
     * Số cảnh và số giây cho một sản phẩm theo cài đặt chung.
     *
     * @return array{scenes: int, seconds: int, preset: ?string, mode: string, reason: string}
     */
    public static function resolve(Product $product): array
    {
        $settings = self::settings();
        if ($settings['mode'] === 'fixed') {
            $scenes = $settings['scene_count'];
            $seconds = $settings['target_seconds'];
            $reason = 'Cố định trong Cài đặt video.';
        } else {
            $recommended = self::recommend($product);
            $scenes = self::PRESETS[$recommended['key']]['scenes'];
            $seconds = self::PRESETS[$recommended['key']]['seconds'];
            $reason = 'Đề xuất theo sản phẩm: '.$recommended['reason'];
        }

        return ['scenes' => $scenes, 'seconds' => $seconds, 'preset' => self::keyFor($scenes, $seconds), 'mode' => $settings['mode'], 'reason' => $reason];
    }

    /** Tên mẫu khớp với số cảnh/giây, null nếu là tuỳ chỉnh. */
    public static function keyFor(int $scenes, int $seconds): ?string
    {
        foreach (self::PRESETS as $key => $preset) {
            if ($preset['scenes'] === $scenes && $preset['seconds'] === $seconds) {
                return $key;
            }
        }

        return null;
    }
}
