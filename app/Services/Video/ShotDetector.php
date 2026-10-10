<?php

namespace App\Services\Video;

use Illuminate\Support\Facades\Process;

/**
 * Tìm các shot của video (đoạn giữa hai lần cắt cảnh) bằng bộ lọc scene của ffmpeg.
 * Video trend đổi đồ thường mỗi shot một bộ: mỗi shot thành (một hoặc vài) cảnh mặc bộ đồ riêng.
 */
class ShotDetector
{
    /** Độ khác giữa hai khung hình để tính là cắt cảnh (0–1). */
    public const THRESHOLD = 0.35;

    /** Shot ngắn hơn (giây) được gộp vào shot trước: thường là chớp sáng / hiệu ứng chuyển. */
    public const MIN_SHOT = 0.8;

    public function __construct(private readonly string $ffmpeg) {}

    public static function fromConfig(): self
    {
        return new self(config('videoaff.ffmpeg'));
    }

    /**
     * @return list<array{start: float, end: float}> Rỗng khi video chỉ có một shot.
     */
    public function detect(string $localPath, float $duration): array
    {
        $result = Process::timeout(180)->run([
            $this->ffmpeg, '-hide_banner', '-i', $localPath,
            '-vf', "select='gt(scene,".self::THRESHOLD.")',showinfo", '-an', '-f', 'null', '-',
        ]);
        if ($result->failed()) {
            return [];
        }

        preg_match_all('/pts_time:([0-9.]+)/', $result->errorOutput().$result->output(), $m);

        return self::shotsFromCuts(array_map('floatval', $m[1]), $duration);
    }

    /**
     * @param  list<float>  $cuts
     * @return list<array{start: float, end: float}>
     */
    public static function shotsFromCuts(array $cuts, float $duration): array
    {
        $bounds = [0.0];
        foreach ($cuts as $cut) {
            if ($cut - end($bounds) >= self::MIN_SHOT && $duration - $cut >= self::MIN_SHOT) {
                $bounds[] = round($cut, 2);
            }
        }
        if (count($bounds) < 2) {
            return [];
        }
        $bounds[] = round($duration, 2);

        $shots = [];
        for ($i = 0; $i < count($bounds) - 1; $i++) {
            $shots[] = ['start' => $bounds[$i], 'end' => $bounds[$i + 1]];
        }

        return $shots;
    }
}
