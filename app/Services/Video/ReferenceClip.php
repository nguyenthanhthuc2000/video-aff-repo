<?php

namespace App\Services\Video;

use App\Models\SampleVideo;
use App\Support\Media;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Bản rút gọn của video mẫu để gửi Muse khi sao chép chuyển động: tối đa 10 giây, cao 720px, 24 fps, không tiếng.
 * File nhỏ (vài MB) nên gửi nhanh và Muse xử lý ổn định hơn video gốc. Tạo một lần rồi dùng lại.
 * Có $start/$length thì cắt đúng đoạn của cảnh (Scene::motionSegment) để mỗi cảnh làm tiếp một đoạn của điệu nhảy.
 */
class ReferenceClip
{
    public const MAX_SECONDS = 10;

    public function __construct(private readonly string $ffmpeg) {}

    public static function fromConfig(): self
    {
        return new self(config('videoaff.ffmpeg'));
    }

    /** Đường dẫn (trên disk media) của bản rút gọn. @throws RuntimeException */
    public function prepare(SampleVideo $video, float $start = 0, ?float $length = null): string
    {
        $segment = $start > 0 || $length !== null;
        $length = min(self::MAX_SECONDS, $length ?? self::MAX_SECONDS);
        $path = $segment
            ? sprintf('sample-videos/%d/ref-%d-%d-%d.mp4', $video->user_id, $video->id, round($start * 1000), round($length * 1000))
            : "sample-videos/{$video->user_id}/ref-{$video->id}.mp4";
        if ($segment ? Media::disk()->exists($path) : ($video->reference_path && Media::disk()->exists($video->reference_path))) {
            return $segment ? $path : $video->reference_path;
        }

        $contents = Media::disk()->get($video->path);
        if ($contents === null) {
            throw new RuntimeException('Không đọc được video mẫu.');
        }

        $input = tempnam(sys_get_temp_dir(), 'sample');
        $output = $input.'-ref.mp4';
        file_put_contents($input, $contents);

        try {
            $result = Process::timeout(180)->run([
                $this->ffmpeg, '-v', 'error', '-y', '-ss', sprintf('%.2F', $start), '-i', $input, '-t', sprintf('%.2F', $length),
                '-vf', 'scale=-2:720,fps=24', '-an', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '28',
                '-pix_fmt', 'yuv420p', '-movflags', '+faststart', $output,
            ]);
            if ($result->failed() || ! is_file($output) || filesize($output) === 0) {
                throw new RuntimeException('Không xử lý được video mẫu: '.mb_substr($result->errorOutput(), 0, 200));
            }

            Media::disk()->put($path, file_get_contents($output));
            if (! $segment) {
                $video->update(['reference_path' => $path]);
            }

            return $path;
        } finally {
            @unlink($input);
            @unlink($output);
        }
    }
}
