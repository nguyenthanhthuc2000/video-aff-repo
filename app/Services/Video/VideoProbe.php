<?php

namespace App\Services\Video;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/** Đọc thông tin video (ffprobe) và chụp ảnh bìa (ffmpeg). Lỗi thì trả null, không chặn việc tải lên. */
class VideoProbe
{
    public function __construct(private readonly string $ffmpeg, private readonly string $ffprobe) {}

    public static function fromConfig(): self
    {
        return new self(config('videoaff.ffmpeg'), config('videoaff.ffprobe'));
    }

    /** @return array{duration_ms: ?int, width: ?int, height: ?int}|null */
    public function probe(string $path): ?array
    {
        $result = Process::timeout(30)->run([
            $this->ffprobe, '-v', 'error', '-select_streams', 'v:0',
            '-show_entries', 'stream=width,height:format=duration', '-of', 'json', $path,
        ]);
        if ($result->failed()) {
            Log::warning('video_probe.failed', ['error' => mb_substr($result->errorOutput(), 0, 300)]);

            return null;
        }

        $data = json_decode($result->output(), true) ?? [];
        $stream = $data['streams'][0] ?? [];
        $duration = (float) ($data['format']['duration'] ?? 0);

        return [
            'duration_ms' => $duration > 0 ? (int) round($duration * 1000) : null,
            'width' => isset($stream['width']) ? (int) $stream['width'] : null,
            'height' => isset($stream['height']) ? (int) $stream['height'] : null,
        ];
    }

    /** Chụp một khung hình (giây thứ $at) làm ảnh bìa JPEG. */
    /** Khung hình cuối của video, đủ nét để làm ảnh mở đầu clip tiếp theo. */
    public function lastFrame(string $path, string $output): bool
    {
        $result = Process::timeout(60)->run([
            $this->ffmpeg, '-v', 'error', '-y', '-sseof', '-0.25', '-i', $path, '-update', '1', '-q:v', '2', $output,
        ]);

        return $result->successful() && is_file($output) && filesize($output) > 0;
    }

    public function thumbnail(string $path, string $output, float $at = 1.0): bool
    {
        $result = Process::timeout(60)->run([
            $this->ffmpeg, '-v', 'error', '-y', '-ss', (string) $at, '-i', $path,
            '-frames:v', '1', '-vf', 'scale=360:-2', '-q:v', '4', $output,
        ]);

        return $result->successful() && is_file($output) && filesize($output) > 0;
    }

    /** Có luồng âm thanh không (null nếu không đọc được). */
    public function hasAudio(string $path): ?bool
    {
        $result = Process::timeout(30)->run([$this->ffprobe, '-v', 'error', '-select_streams', 'a', '-show_entries', 'stream=index', '-of', 'csv=p=0', $path]);

        return $result->successful() ? trim($result->output()) !== '' : null;
    }
}
