<?php

namespace App\Support;

use App\Models\AppSetting;
use App\Services\Film\FakeFilmClipGenerator;
use App\Services\Film\FakeFilmImageGenerator;
use App\Services\Film\FilmMuseClipGenerator;
use App\Services\Film\FilmMuseImageGenerator;
use App\Services\Image\AsyncImageGenerator;
use App\Services\Image\MuseBrowserClient;
use App\Services\MiniMax\MiniMaxClipGenerator;
use App\Services\MiniMax\MiniMaxImageGenerator;
use App\Services\Music\FalSongGenerator;
use App\Services\Video\ClipGenerator;

/**
 * Chọn công cụ AI cho từng bước của phim / MV (trang Kết nối AI): ảnh nhân vật, khung hình, clip, nhạc. Mặc định: MiniMax khi khoá MiniMax
 * đã kiểm tra được, không thì như trước (Muse qua trình duyệt / ảnh giả khi chưa nối Muse). Lựa chọn lưu trong app_settings.
 */
final class AiProviders
{
    public const SETTING = 'ai_providers';

    public const STEPS = [
        'character_image' => 'Ảnh nhân vật',
        'keyframe' => 'Khung hình shot',
        'clip' => 'Clip chuyển động',
        'music' => 'Tạo nhạc bài mới',
    ];

    /**
     * Các lựa chọn của một bước: tên, dùng được không, ghi chú, chi phí ước tính.
     *
     * @return array<string, array{label: string, available: bool, note: string, cost: string}>
     */
    public static function options(string $step): array
    {
        $muse = config('videoaff.image_driver') === 'browser';
        $minimax = AiKeys::get('minimax') !== null;
        $fal = AiKeys::get('fal') !== null;
        $fake = in_array(config('videoaff.image_driver'), ['fake', 'web'], true);
        $museOption = ['label' => 'Muse qua trình duyệt', 'available' => $muse, 'note' => $muse ? 'Miễn phí, chậm (vài phút mỗi lần).' : 'Chưa bật MUSE_DRIVER=browser.', 'cost' => 'Miễn phí'];
        $fakeOption = $fake ? ['fake' => ['label' => 'Giả lập (thử luồng)', 'available' => true, 'note' => 'Ảnh / clip mẫu, không tạo thật.', 'cost' => '0']] : [];

        return match ($step) {
            'character_image', 'keyframe' => [
                'minimax' => ['label' => 'MiniMax image-01', 'available' => $minimax, 'note' => $minimax ? 'Nhanh; chỉ nhận một ảnh tham chiếu nhân vật mỗi ảnh.' : 'Cần khoá MiniMax.', 'cost' => '~0,0035 USD / ảnh'],
                'muse' => $museOption,
                ...$fakeOption,
            ],
            'clip' => [
                'minimax' => ['label' => 'Hailuo ('.config('videoaff.minimax.video_model').', '.config('videoaff.minimax.video_resolution').')', 'available' => $minimax,
                    'note' => $minimax ? 'Nhanh, đẹp; KHÔNG hát nhép theo đúng đoạn nhạc (Hailuo không nhận file tiếng).' : 'Cần khoá MiniMax.',
                    'cost' => '~'.MiniMaxClipGenerator::price(6).' USD / clip 6 giây'],
                'muse' => [...$museOption, 'note' => $museOption['note'].' Hát nhép theo đoạn nhạc gửi kèm.'],
                ...$fakeOption,
            ],
            default => [
                ...collect(FalSongGenerator::MODELS)->mapWithKeys(fn ($m, $k) => [$k => [
                    'label' => "{$m['label']} qua fal", 'available' => $fal, 'note' => $fal ? $m['note'] : 'Cần khoá fal.',
                    'cost' => '~'.FalSongGenerator::estimate($k, 180).' USD / bài 3 phút',
                ]])->all(),
                'minimax-direct' => ['label' => 'MiniMax Music trực tiếp', 'available' => $minimax,
                    'note' => 'Chỉ tài khoản MiniMax đã trả phí trước 20/08/2026 (MiniMax ngừng mở API nhạc cho người dùng mới).', 'cost' => '~0,15 USD / bài'],
                'manual' => ['label' => 'Tự làm trên Suno / Udio rồi tải lên', 'available' => true, 'note' => 'Miễn phí / theo gói của bạn.', 'cost' => 'Theo công cụ'],
            ],
        };
    }

    /** Công cụ đang dùng cho bước: lựa chọn đã lưu (nếu còn dùng được), không thì mặc định. */
    public static function selected(string $step): string
    {
        $options = self::options($step);
        $saved = (json_decode((string) AppSetting::get(self::SETTING), true) ?: [])[$step] ?? null;
        if ($saved && ($options[$saved]['available'] ?? false)) {
            return $saved;
        }
        $preferred = match ($step) {
            'music' => [AiKeys::get('fal') ? 'minimax' : null, 'manual'],
            default => [AiKeys::valid('minimax') ? 'minimax' : null, 'muse', 'fake'],
        };
        foreach (array_filter($preferred) as $key) {
            if ($options[$key]['available'] ?? false) {
                return $key;
            }
        }

        return array_key_first(array_filter($options, fn ($o) => $o['available'])) ?? array_key_first($options);
    }

    public static function save(array $choices): void
    {
        $current = json_decode((string) AppSetting::get(self::SETTING), true) ?: [];
        foreach (self::STEPS as $step => $label) {
            if (isset($choices[$step]) && isset(self::options($step)[$choices[$step]])) {
                $current[$step] = $choices[$step];
            }
        }
        AppSetting::put(self::SETTING, json_encode($current));
    }

    public static function imageGenerator(string $step): AsyncImageGenerator
    {
        return match (self::selected($step)) {
            'minimax' => new MiniMaxImageGenerator,
            'muse' => app(FilmMuseImageGenerator::class),
            default => app(FakeFilmImageGenerator::class),
        };
    }

    public static function clipGenerator(): ClipGenerator
    {
        return match (self::selected('clip')) {
            'minimax' => new MiniMaxClipGenerator,
            'muse' => new FilmMuseClipGenerator(app(MuseBrowserClient::class)),
            default => new FakeFilmClipGenerator,
        };
    }
}
