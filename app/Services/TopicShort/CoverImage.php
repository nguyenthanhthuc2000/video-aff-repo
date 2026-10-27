<?php

namespace App\Services\TopicShort;

use App\Models\TopicShort;
use App\Models\TopicShortScene;
use App\Support\Media;
use GdImage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Ảnh bìa 1080x1920 cho short, kiểu chữ chèn tay trên TikTok / Shorts: ảnh một cảnh phủ kín (tối nhẹ),
 * chữ trắng đậm trên dải đen từng dòng, đặt giữa khung (Be Vietnam Pro). Không viền vàng, không dải màu, cho đỡ "chất AI".
 * Nền ghép bằng GD, chữ vẽ bằng ffmpeg, chạy trên máy, không tốn Credits.
 */
final class CoverImage
{
    public const WIDTH = 1080;

    public const HEIGHT = 1920;

    private const FONT_SIZE = 84;

    private const MAX_LINES = 4;

    /** Cảnh mặc định làm nền: ưu tiên ảnh thật (khung manga, ảnh tải lên / tìm trên mạng) hơn ảnh Muse vẽ, cảnh đầu trước. */
    public static function defaultScene(TopicShort $short): ?TopicShortScene
    {
        $scenes = $short->scenes()->where('image_status', 'ready')->whereNotNull('image_path')->orderBy('position')->get();

        return $scenes->first(fn (TopicShortScene $scene) => $scene->image_source !== 'muse') ?? $scenes->first();
    }

    /** @throws RuntimeException */
    public static function make(TopicShort $short, TopicShortScene $scene, string $text): string
    {
        $contents = $scene->image_path ? Media::disk()->get($scene->image_path) : null;
        $source = $contents ? @imagecreatefromstring($contents) : false;
        if (! $source instanceof GdImage) {
            throw new RuntimeException("Cảnh {$scene->position} chưa có ảnh dùng được.");
        }

        $page = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        self::cover($page, $source);
        imagedestroy($source);
        self::shade($page);
        $jpeg = self::title($page, trim($text));
        imagedestroy($page);

        $path = "topic-shorts/{$short->id}/cover-".substr(md5($jpeg), 0, 8).'.jpg';
        Media::disk()->put($path, $jpeg);
        $old = $short->cover_path;
        $short->update(['cover_path' => $path, 'cover' => ['scene_id' => $scene->id, 'text' => trim($text)]]);
        $old && $old !== $path && Media::delete($old);

        return $path;
    }

    /** Phủ kín khung, cắt phần thừa (giữ phần trên, nơi thường có mặt nhân vật). */
    private static function cover(GdImage $page, GdImage $image): void
    {
        $sw = imagesx($image);
        $sh = imagesy($image);
        $scale = max(self::WIDTH / $sw, self::HEIGHT / $sh);
        $cw = (int) round(self::WIDTH / $scale);
        $ch = (int) round(self::HEIGHT / $scale);
        imagecopyresampled($page, $image, 0, 0, intdiv($sw - $cw, 2), (int) round(($sh - $ch) / 4), self::WIDTH, self::HEIGHT, $cw, $ch);
    }

    /** Tối nhẹ cả ảnh để chữ nổi, không làm dải màu. */
    private static function shade(GdImage $page): void
    {
        imagefilledrectangle($page, 0, 0, self::WIDTH, self::HEIGHT, imagecolorallocatealpha($page, 0, 0, 0, 100));
    }

    /**
     * Chữ trắng trên dải đen từng dòng, căn giữa khung, tối đa 4 dòng. Vẽ bằng ffmpeg drawtext: FreeType cũ của GD đặt sai dấu tiếng Việt
     * (dấu nặng, dấu mũ bị lệch hoặc mất).
     *
     * @throws RuntimeException
     */
    private static function title(GdImage $page, string $text): string
    {
        $dir = storage_path('app/tmp/cover-'.Str::random(10));
        File::ensureDirectoryExists($dir);
        try {
            imagepng($page, "{$dir}/bg.png");
            $filters = [];
            if ($text !== '') {
                $font = resource_path('fonts/BeVietnamPro-Bold.ttf');
                $length = mb_strlen($text);
                $size = match (true) {
                    $length <= 24 => self::FONT_SIZE,
                    $length <= 40 => 72,
                    $length <= 56 => 62,
                    default => 54,
                };
                $lines = array_slice(self::balanced($text, $font, $size, self::WIDTH - 220), 0, self::MAX_LINES);
                // GD đo theo point (96 dpi), ffmpeg vẽ theo pixel.
                $px = (int) round($size * 96 / 72);
                $pad = (int) round($px * 0.28);
                $lineHeight = $px + 2 * $pad + 8;
                $y = (int) round((self::HEIGHT - count($lines) * $lineHeight) / 2) + $pad;
                foreach ($lines as $i => $line) {
                    File::put("{$dir}/line{$i}.txt", $line);
                    $filters[] = "drawtext=fontfile='".self::escape($font)."':textfile='".self::escape("{$dir}/line{$i}.txt")."'"
                        .":fontsize={$px}:fontcolor=white:box=1:boxcolor=black@0.88:boxborderw={$pad}"
                        .':x=(w-text_w)/2:y='.($y + $i * $lineHeight);
                }
            }
            $result = Process::timeout(60)->run([
                config('videoaff.ffmpeg'), '-v', 'error', '-y', '-nostdin', '-i', "{$dir}/bg.png",
                ...$filters === [] ? [] : ['-vf', implode(',', $filters)], '-frames:v', '1', '-q:v', '3', "{$dir}/cover.jpg",
            ]);
            if ($result->failed() || ! is_file("{$dir}/cover.jpg")) {
                throw new RuntimeException('Tạo ảnh bìa lỗi: '.mb_substr(trim($result->errorOutput()), -300));
            }

            return (string) file_get_contents("{$dir}/cover.jpg");
        } finally {
            File::deleteDirectory($dir);
        }
    }

    private static function escape(string $path): string
    {
        return str_replace(['\\', "'", ':'], ['\\\\', "\\'", '\\:'], $path);
    }

    /**
     * Xuống dòng đều tay: giữ số dòng ít nhất, rồi thu hẹp dần bề rộng để các dòng dài gần bằng nhau (không để một chữ lẻ ở dòng cuối).
     *
     * @return list<string>
     */
    private static function balanced(string $text, string $font, int $size, int $width): array
    {
        $lines = self::wrap($text, $font, $size, $width);
        for ($w = $width - 20; count($lines) > 1 && $w > $width / 3; $w -= 20) {
            $narrower = self::wrap($text, $font, $size, $w);
            if (count($narrower) > count($lines)) {
                break;
            }
            $lines = $narrower;
        }

        return $lines;
    }

    /** @return list<string> */
    private static function wrap(string $text, string $font, int $size, int $width): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $try = $line === '' ? $word : "{$line} {$word}";
            $box = imagettfbbox($size, 0, $font, $try);
            if ($line !== '' && $width < $box[2] - $box[0]) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $try;
            }
        }
        $line !== '' && $lines[] = $line;

        return $lines;
    }
}
