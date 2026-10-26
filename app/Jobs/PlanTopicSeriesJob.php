<?php

namespace App\Jobs;

use App\Models\TopicShort;
use App\Services\Claude\AiUsage;
use App\Services\Script\ScriptWriterException;
use App\Services\TopicShort\SeriesPlanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Claude chia series thành các phần rồi viết kịch bản từng phần. */
class PlanTopicSeriesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public TopicShort $short) {}

    public function handle(): void
    {
        $short = $this->short->fresh();
        if ($short === null || $short->status !== 'writing' || $short->topic_series_id !== null) {
            return;
        }

        try {
            $shorts = AiUsage::withContext(['user_id' => $short->user_id, 'subject' => "topic_short:{$short->id}"], fn () => SeriesPlanner::plan($short));
        } catch (ScriptWriterException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 120);

                return;
            }
            $this->fail($e);

            return;
        }

        foreach ($shorts as $part) {
            WriteTopicShortJob::dispatch($part);
        }
    }

    public function failed(?Throwable $e): void
    {
        $this->short->fresh()?->update(['status' => 'failed', 'error' => mb_substr($e?->getMessage() ?: 'Chia series thất bại.', 0, 500)]);
    }
}
