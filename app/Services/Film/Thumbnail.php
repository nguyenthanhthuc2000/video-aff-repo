<?php

namespace App\Services\Film;

use App\Models\EnglishLesson;
use App\Models\EpisodeRender;
use App\Models\Shot;
use App\Services\English\Curriculum;
use App\Support\Media;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Ảnh bìa YouTube (JPG dưới 2 MB, đúng khổ của video: 1280×720 cho ngang, 1080×1920 cho dọc).
 * Tập phim: khung hình nổi bật nhất (nhiều nhân vật, cận cảnh, cảm xúc mạnh) tăng màu, chữ to viền đậm + tên phim.
 * Bài tiếng Anh: nền màu của tuần, "DAY N" + chủ đề.
 */
final class Thumbnail
{
    private const STRONG = ['excited', 'surprised', 'laughing', 'scared', 'angry', 'happy', 'proud'];

    /** Shot có khung hình đáng làm ảnh bìa nhất. */
    public static function pickShot(EpisodeRender $render): ?Shot
    {
        $shots = $render->episode->shots()->whereNotNull('image_path')->get();

        return $shots->sortByDesc(function (Shot $shot, int $i) use ($shots) {
            $emotions = collect($shot->dialogue ?? [])->pluck('emotion')->intersect(self::STRONG)->count();
            $camera = match (true) {
                str_contains($shot->camera, 'close-up') => 3,
                in_array($shot->camera, ['medium shot', 'two-shot', 'low angle shot'], true) => 2,
                str_contains($shot->camera, 'establishing') || str_contains($shot->camera, 'insert') => -3,
                default => 0,
            };

            // Ưu tiên khúc giữa / cao trào của tập hơn mấy shot mở đầu.
            return min(3, count($shot->character_ids ?? [])) * 2 + $camera + $emotions * 2 + ($i > $shots->count() / 4 ? 1 : 0);
        })->first();
    }

    /** Nhân vật cho ảnh bìa AI: nhân vật của shot nổi bật, thêm nhân vật chính của tập, tối đa 3 (có ảnh chính). */
    public static function artCharacters(EpisodeRender $render): Collection
    {
        $episode = $render->episode;
        $ids = collect(self::pickShot($render)?->character_ids ?? [])->merge($episode->character_ids ?? [])->unique()->values();
        $found = $episode->series->characters()->whereIn('id', $ids)->whereNotNull('main_image_path')->get()->keyBy('id');

        return $ids->map(fn ($id) => $found[$id] ?? null)->filter()->take(3)->values();
    }

    /** Prompt Muse vẽ riêng ảnh nền cho ảnh bìa: nhân vật to, biểu cảm mạnh, nền gọn, chừa chỗ cho chữ (chữ in sau bằng ffmpeg). */
    public static function artPrompt(EpisodeRender $render, ?string $notes = null): string
    {
        $episode = $render->episode;
        $series = $episode->series;
        $shot = self::pickShot($render);
        $n = 0;
        $lines = self::artCharacters($render)->map(fn ($c) => "- {$c->name} (exactly as in attached image ".(++$n)."): {$c->appearance}")->implode("\n");
        $frame = $render->vertical() ? 'TALL VERTICAL 9:16 portrait frame' : 'WIDE HORIZONTAL 16:9 landscape frame';
        $space = $render->vertical() ? 'Keep the top quarter of the frame simple (sky / soft background) for a title added later; characters in the lower two thirds.'
            : 'Characters fill the left and center; keep the bottom third and the right side calmer for a title added later.';

        return "Eye-catching thumbnail illustration for the episode \"{$episode->title}\" of the animated series \"{$series->title}\". Frame: {$frame}.\n"
            .($episode->synopsis ? 'Story: '.Str::limit($episode->synopsis, 400)."\n" : '')
            .($shot ? "Key moment to show: {$shot->action}\n" : '')
            .($lines !== '' ? "Characters (big in frame, close-up, exaggerated clear expressions reacting to the moment; keep faces, fur / hair, outfits and colors identical):\n{$lines}\n" : '')
            ."{$space}\n"
            .'Bold readable composition with one clear focal point, strong color contrast and rim light, saturated cheerful colors, simple uncluttered background, '
            .'nothing scary or dangerous. No text, letters, logos or watermarks.'."\n"
            .(filled($notes) ? 'Additional direction: '.trim($notes).".\n" : '')
            ."Art style: {$series->style}.";
    }

    /**
     * Tạo (lại) ảnh bìa cho một bản xuất của tập. $art: dùng ảnh nền Muse vẽ riêng (nếu đã có) thay cho khung hình shot.
     *
     * @throws RuntimeException
     */
    public static function forRender(EpisodeRender $render, ?int $shotId = null, ?string $text = null, bool $art = false): string
    {
        $episode = $render->episode;
        $art = $art && $render->art_path !== null;
        $shot = ! $art && $shotId ? $episode->shots()->whereNotNull('image_path')->find($shotId) : null;
        $shot ??= $art ? null : self::pickShot($render);
        $source = $art ? (string) $render->art_path : $shot?->image_path;
        $text = trim((string) $text) !== '' ? trim($text) : ($render->lang === 'vi' ? ($episode->dub_vi['title'] ?? $episode->title) : $episode->title);
        $printed = self::plain($text);
        [$w, $h] = $render->vertical() ? [1080, 1920] : [1280, 720];

        $dir = sys_get_temp_dir().'/thumb-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        try {
            $input = $source !== null
                ? ['-i', self::local($source, "{$dir}/frame.".(pathinfo($source, PATHINFO_EXTENSION) ?: 'png'))]
                : ['-f', 'lavfi', '-i', "color=c=0x1e293b:s={$w}x{$h}"];
            $vertical = $render->vertical();
            // Tiêu đề dài: chữ nhỏ dần cho đủ trong 3 dòng, không bị cắt mất chữ.
            [$width, $size] = self::fit($printed, $vertical ? [[14, 118], [17, 98], [20, 84], [24, 70]] : [[18, 92], [22, 76], [27, 62], [32, 52]]);
            File::put("{$dir}/title.txt", self::wrap($printed, $width));
            $titleLines = self::wrap($printed, $width);
            File::put("{$dir}/series.txt", $episode->series->title);
            $font = self::escape(resource_path('fonts/BeVietnamPro-Bold.ttf'));
            $filter = "scale={$w}:{$h}:force_original_aspect_ratio=increase,crop={$w}:{$h},eq=saturation=1.35:contrast=1.08:brightness=0.02,vignette=PI/5,"
                // Dải tối phía sau chữ cho dễ đọc.
                .($vertical ? 'drawbox=x=0:y=ih*0.06:w=iw:h=ih*0.3:color=black@0.35:t=fill,' : 'drawbox=x=0:y=ih*0.55:w=iw:h=ih*0.45:color=black@0.35:t=fill,')
                .($vertical
                    ? implode(',', self::centeredLines($dir, 'title', $titleLines, $font, $size, '0xFFE14D', 190, (int) round($size * 1.19), 10))
                    : "drawtext=fontfile='{$font}':textfile='".self::escape("{$dir}/title.txt")."':fontsize={$size}:fontcolor=0xFFE14D:borderw=8:bordercolor=black:line_spacing=6:x=48:y=h-text_h-56").','
                ."drawtext=fontfile='{$font}':textfile='".self::escape("{$dir}/series.txt")."':fontsize=".($vertical ? 46 : 34)
                .':fontcolor=white:box=1:boxcolor=0xE11D48@0.92:boxborderw=14:'.($vertical ? 'x=(w-text_w)/2:y=h*0.04' : 'x=48:y=40');
            $out = "{$dir}/thumb.jpg";
            self::ffmpeg([...$input, '-frames:v', '1', '-vf', $filter, '-q:v', '3', $out]);

            $path = preg_replace('/\.mp4$/', '', $render->path).'-thumb-'.bin2hex(random_bytes(3)).'.jpg';
            Media::disk()->put($path, (string) File::get($out));
            $old = $render->thumbnail_path;
            $render->update(['thumbnail_path' => $path, 'thumbnail_options' => ['shot_id' => $shot?->id, 'text' => $text, 'art' => $art]]);
            $old !== null && $old !== $path && Media::delete($old);

            return $path;
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * Ảnh bìa bài tiếng Anh (dọc như video Shorts).
     *
     * @throws RuntimeException
     */
    public static function forLesson(EnglishLesson $lesson): string
    {
        $dir = sys_get_temp_dir().'/thumb-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        try {
            $week = Curriculum::day($lesson->day)['week'];
            $colors = [['0x1e3a8a', '0x6d28d9'], ['0x0f766e', '0x1d4ed8'], ['0x9a3412', '0xbe185d'], ['0x166534', '0x0e7490']][($week - 1) % 4];
            File::put("{$dir}/day.txt", "DAY {$lesson->day}");

            File::put("{$dir}/tag.txt", ($lesson->kind === 'speaking' ? 'NÓI 30 GIÂY' : '5 CÂU MỖI NGÀY').'  ·  A2 lên B1');
            $font = self::escape(resource_path('fonts/BeVietnamPro-Bold.ttf'));
            $semi = self::escape(resource_path('fonts/BeVietnamPro-SemiBold.ttf'));
            $text = fn (string $file, string $f, int $size, string $color, string $y, int $border = 6) => "drawtext=fontfile='{$f}':textfile='".self::escape("{$dir}/{$file}")
                ."':fontsize={$size}:fontcolor={$color}:borderw={$border}:bordercolor=black@0.55:line_spacing=10:x=(w-text_w)/2:y={$y}";
            $filter = implode(',', [
                $text('day.txt', $font, 230, 'white', 'h*0.22', 10),
                $text('tag.txt', $semi, 52, '0xFDE68A', 'h*0.38'),
                ...self::centeredLines($dir, 'topic', self::wrap($lesson->topic, 16), $font, 120, '0xFFE14D', 900, 140, 8),
                ...self::centeredLines($dir, 'vi', self::wrap($lesson->topic_vi, 24), $semi, 64, 'white', 1360, 82, 5),
            ]);
            $out = "{$dir}/thumb.jpg";
            self::ffmpeg(['-f', 'lavfi', '-i', "gradients=s=1080x1920:c0={$colors[0]}:c1={$colors[1]}:x0=0:y0=0:x1=1080:y1=1920:d=1", '-frames:v', '1', '-vf', $filter, '-q:v', '3', $out]);
            $path = "english/{$lesson->user_id}/day-{$lesson->day}/thumb-".bin2hex(random_bytes(3)).'.jpg';
            Media::disk()->put($path, (string) File::get($out));
            $old = $lesson->thumbnail_path;
            $lesson->update(['thumbnail_path' => $path]);
            $old !== null && $old !== $path && Media::delete($old);

            return $path;
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * Mỗi dòng một drawtext canh giữa (drawtext canh cả khối theo dòng dài nhất).
     *
     * @return list<string>
     */
    private static function centeredLines(string $dir, string $name, string $text, string $font, int $size, string $color, int $top, int $step, int $border): array
    {
        return array_map(function (string $line, int $i) use ($dir, $name, $font, $size, $color, $top, $step, $border) {
            File::put("{$dir}/{$name}-{$i}.txt", $line);

            return "drawtext=fontfile='{$font}':textfile='".self::escape("{$dir}/{$name}-{$i}.txt")."':fontsize={$size}:fontcolor={$color}:borderw={$border}"
                .':bordercolor=black@0.7:x=(w-text_w)/2:y='.($top + $i * $step);
        }, explode("\n", $text), array_keys(explode("\n", $text)));
    }

    /**
     * Cỡ chữ lớn nhất mà tiêu đề vừa 3 dòng (không từ nào bị bỏ).
     *
     * @param  list<array{int, int}>  $options  [số ký tự mỗi dòng, cỡ chữ], từ to đến nhỏ
     * @return array{int, int}
     */
    private static function fit(string $text, array $options): array
    {
        foreach ($options as $option) {
            if (count(self::lines($text, $option[0])) <= 3) {
                return $option;
            }
        }

        return $options[array_key_last($options)];
    }

    /** Bỏ emoji (font in chữ không có, sẽ ra ô vuông) và khoảng trắng thừa. */
    public static function plain(string $text): string
    {
        $text = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}\x{20E3}]/u', '', $text) ?? $text;

        return trim((string) preg_replace('/\s{2,}/u', ' ', $text));
    }

    /** Ngắt dòng theo số ký tự (drawtext không tự xuống dòng), tối đa 3 dòng. */
    public static function wrap(string $text, int $width): string
    {
        // wordwrap đếm byte: chữ có dấu tiếng Việt bị xuống dòng sớm, nên tự gộp từ theo số ký tự.
        return implode("\n", array_slice(self::lines($text, $width), 0, 3));
    }

    /** @return list<string> */
    private static function lines(string $text, int $width): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', trim($text)) as $word) {
            if ($line !== '' && mb_strlen("{$line} {$word}") > $width) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $line === '' ? $word : "{$line} {$word}";
            }
        }
        $line !== '' && $lines[] = $line;

        return $lines;
    }

    private static function local(string $mediaPath, string $target): string
    {
        $contents = Media::disk()->get($mediaPath);
        if ($contents === null) {
            throw new RuntimeException("Không tìm thấy file {$mediaPath}.");
        }
        File::put($target, $contents);

        return $target;
    }

    /** @param list<string> $args */
    private static function ffmpeg(array $args): void
    {
        $result = Process::timeout(60)->run([config('videoaff.ffmpeg'), '-v', 'error', '-y', '-nostdin', ...$args]);
        if ($result->failed()) {
            throw new RuntimeException('Tạo ảnh bìa lỗi: '.mb_substr(trim($result->errorOutput()), -300));
        }
    }

    private static function escape(string $path): string
    {
        return str_replace(['\\', "'", ':'], ['\\\\', "\\'", '\\:'], $path);
    }
}
