<?php

namespace App\Jobs;

use App\Models\SampleVideo;
use App\Services\Claude\AiUsage;
use App\Services\Video\Analysis\MotionAnalyzer;
use App\Support\CreditPricing;
use App\Support\Credits;
use App\Support\Media;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/** Phân tích chuyển động của video mẫu: chụp 2 khung hình mỗi giây (tối đa 10 giây) rồi nhờ Claude tả từng nhịp. */
class AnalyzeSampleVideoJob implements ShouldQueue
{
    use Queueable;

    public const FPS = 2;

    public const MAX_SECONDS = 10;

    public int $tries = 2;

    public int $timeout = 400;

    public int $backoff = 60;

    public function __construct(public SampleVideo $video) {}

    public function handle(MotionAnalyzer $analyzer): void
    {
        if ($message = Credits::shortfall($this->video->user, CreditPricing::aiEstimates()['motion_analysis'], 'phân tích video mẫu')) {
            $this->video->update(['analysis_status' => 'failed', 'analysis_error' => $message]);

            return;
        }

        $contents = Media::disk()->get($this->video->path) ?? throw new RuntimeException('Không đọc được video mẫu.');
        $dir = sys_get_temp_dir().'/frames-'.uniqid();
        mkdir($dir);
        $input = "{$dir}/input";
        file_put_contents($input, $contents);

        try {
            $result = Process::timeout(120)->run([
                config('videoaff.ffmpeg'), '-v', 'error', '-y', '-i', $input, '-t', (string) self::MAX_SECONDS,
                '-vf', 'fps='.self::FPS.',scale=320:-2', '-q:v', '5', "{$dir}/frame_%02d.jpg",
            ]);
            $files = glob("{$dir}/frame_*.jpg") ?: [];
            sort($files);
            if ($result->failed() || $files === []) {
                throw new RuntimeException('Không chụp được khung hình từ video mẫu.');
            }

            $frames = array_map(fn (string $file, int $i) => ['time' => $i / self::FPS, 'contents' => file_get_contents($file)], $files, array_keys($files));
            $duration = min(self::MAX_SECONDS, ($this->video->duration_ms ?? count($files) * 1000 / self::FPS) / 1000);

            $this->video->update([
                'motion_analysis' => AiUsage::withContext(
                    ['user_id' => $this->video->user_id, 'subject' => "sample_video:{$this->video->id}"],
                    fn () => $analyzer->analyze($frames, $duration),
                ),
                'analysis_status' => 'ready',
                'analysis_error' => null,
            ]);
        } finally {
            array_map('unlink', glob("{$dir}/*") ?: []);
            @rmdir($dir);
        }
    }

    public function failed(?Throwable $e): void
    {
        $this->video->update(['analysis_status' => 'failed', 'analysis_error' => mb_substr($e?->getMessage() ?? 'Phân tích thất bại.', 0, 500)]);
    }
}
