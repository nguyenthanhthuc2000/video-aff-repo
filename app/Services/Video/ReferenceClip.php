<?php

namespace App\Services\Video;

use App\Models\SampleVideo;
use App\Support\Media;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Bản rút gọn của video mẫu để gửi Muse khi sao chép chuyển động: tối đa 10 giây, cao 720px, 24 fps, không tiếng.
 * File nhỏ (vài MB) nên gửi nhanh và Muse xử lý ổn định hơn video gốc. Tạo một lần rồi dùng lại.
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
    public function prepare(SampleVideo $video): string
    {
        if ($video->reference_path && Media::disk()->exists($video->reference_path)) {
            return $video->reference_path;
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
                $this->ffmpeg, '-v', 'error', '-y', '-i', $input, '-t', (string) self::MAX_SECONDS,
                '-vf', 'scale=-2:720,fps=24', '-an', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '28',
                '-pix_fmt', 'yuv420p', '-movflags', '+faststart', $output,
            ]);
            if ($result->failed() || ! is_file($output) || filesize($output) === 0) {
                throw new RuntimeException('Không xử lý được video mẫu: '.mb_substr($result->errorOutput(), 0, 200));
            }

            $path = "sample-videos/{$video->user_id}/ref-{$video->id}.mp4";
            Media::disk()->put($path, file_get_contents($output));
            $video->update(['reference_path' => $path]);

            return $path;
        } finally {
            @unlink($input);
            @unlink($output);
        }
    }
}
