<?php

namespace App\Services\Audio;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Phân tích nhạc nền cho hát nhép (ffmpeg, không tốn Credits): đo độ lớn từng giây để chọn đoạn sôi động nhất
 * (thường là điệp khúc, có giọng hát) vừa đúng độ dài video, rồi cắt đúng đoạn nhạc của từng cảnh gửi kèm khi tạo clip.
 */
class MusicAnalyzer
{
    public function __construct(private readonly string $ffmpeg) {}

    public static function fromConfig(): self
    {
        return new self(config('videoaff.ffmpeg'));
    }

    /**
     * Độ lớn (RMS, dB) của từng giây.
     *
     * @return list<float>
     *
     * @throws RuntimeException
     */
    public function levels(string $path): array
    {
        $file = tempnam(sys_get_temp_dir(), 'levels');
        try {
            $result = Process::timeout(120)->run([
                $this->ffmpeg, '-v', 'error', '-i', $path, '-vn',
                // Đổi về 8 kHz mono trong chuỗi lọc để mỗi khung 8000 mẫu đúng 1 giây.
                '-af', 'aresample=8000,aformat=channel_layouts=mono,asetnsamples=n=8000,astats=metadata=1:reset=1,ametadata=print:key=lavfi.astats.Overall.RMS_level:file='.$file,
                '-f', 'null', '-',
            ]);
            if ($result->failed()) {
                throw new RuntimeException('Không phân tích được nhạc nền.');
            }
            preg_match_all('/RMS_level=(-?[\d.]+|-inf)/', (string) file_get_contents($file), $m);

            return array_map(fn (string $db) => $db === '-inf' ? -120.0 : (float) $db, $m[1]);
        } finally {
            @unlink($file);
        }
    }

    /** Giây bắt đầu của đoạn dài $seconds to nhất (trung bình năng lượng), nhạc ngắn hơn video thì từ 0. */
    public static function bestStart(array $levels, int $seconds): int
    {
        $count = count($levels);
        $seconds = max(1, $seconds);
        if ($count <= $seconds) {
            return 0;
        }

        $energy = array_map(fn (float $db) => 10 ** ($db / 10), $levels);
        $sum = array_sum(array_slice($energy, 0, $seconds));
        [$best, $bestSum] = [0, $sum];
        for ($start = 1; $start + $seconds <= $count; $start++) {
            $sum += $energy[$start + $seconds - 1] - $energy[$start - 1];
            // Hơn hẳn mới đổi, để ưu tiên đoạn sớm khi gần bằng nhau.
            if ($sum > $bestSum * 1.02) {
                [$best, $bestSum] = [$start, $sum];
            }
        }

        return $best;
    }

    /**
     * Cắt đoạn nhạc (lặp lại bài nếu hết bài).
     *
     * @throws RuntimeException
     */
    public function segment(string $path, int $startMs, int $lengthMs, string $output): void
    {
        $result = Process::timeout(120)->run([
            $this->ffmpeg, '-v', 'error', '-y', '-stream_loop', '-1', '-i', $path,
            '-ss', (string) ($startMs / 1000), '-t', (string) (max(500, $lengthMs) / 1000),
            '-vn', '-c:a', 'aac', '-b:a', '160k', $output,
        ]);
        if ($result->failed() || ! is_file($output) || filesize($output) === 0) {
            throw new RuntimeException('Không cắt được đoạn nhạc cho cảnh.');
        }
    }
}
