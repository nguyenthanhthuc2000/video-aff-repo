<?php

namespace App\Jobs;

use App\Models\AudioTrack;
use App\Services\Audio\VocalAnalyzer;
use App\Support\Media;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Phân tích giọng hát của một bài trong thư viện (miễn phí, chạy trên máy): khoảng có lời và lời kèm mốc thời gian.
 * Gửi cho service rồi hỏi lại định kỳ, không giữ hàng đợi trong lúc tách giọng (vài phút).
 */
class AnalyzeVocalsJob implements ShouldQueue
{
    use Queueable;

    public const POLL_SECONDS = 15;

    public int $timeout = 120;

    public function __construct(public AudioTrack $track) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(40);
    }

    public function handle(VocalAnalyzer $analyzer): void
    {
        $track = $this->track;
        $key = "vocal-task:{$track->id}";

        try {
            if (! Cache::has($key)) {
                $contents = Media::disk()->get((string) $track->path) ?? throw new RuntimeException('Không đọc được file nhạc.');
                Cache::put($key, $analyzer->start($contents, basename((string) $track->path)), now()->addHour());
                $this->release(self::POLL_SECONDS);

                return;
            }

            $result = $analyzer->poll((string) Cache::get($key));
        } catch (RuntimeException $e) {
            Cache::forget($key);
            $this->fail($e);

            return;
        }

        if ($result === null) {
            $this->release(self::POLL_SECONDS);

            return;
        }

        Cache::forget($key);
        $track->update(['vocal_analysis' => $result, 'vocal_status' => 'ready', 'vocal_error' => null]);
    }

    public function failed(?Throwable $e): void
    {
        Cache::forget("vocal-task:{$this->track->id}");
        $this->track->update(['vocal_status' => 'failed', 'vocal_error' => mb_substr($e?->getMessage() ?? 'Phân tích giọng hát thất bại.', 0, 500)]);
    }
}
