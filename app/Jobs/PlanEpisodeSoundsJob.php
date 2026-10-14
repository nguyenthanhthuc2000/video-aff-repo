<?php

namespace App\Jobs;

use App\Models\Episode;
use App\Services\Claude\AiUsage;
use App\Services\Film\SoundPlanner;
use App\Services\Script\ScriptWriterException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Claude chọn âm thanh hiệu ứng cho cả tập (từ kho miễn phí). */
class PlanEpisodeSoundsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public Episode $episode) {}

    public function handle(): void
    {
        $episode = $this->episode->fresh();
        if ($episode === null || $episode->sound_status !== 'planning') {
            return;
        }
        try {
            $plan = AiUsage::withContext(
                ['user_id' => $episode->series->user_id, 'subject' => "series:{$episode->series_id}"],
                fn () => SoundPlanner::plan($episode),
            );
        } catch (ScriptWriterException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 120);

                return;
            }
            $this->fail($e);

            return;
        }
        $episode->update(['sound_plan' => $plan, 'sound_status' => 'ready', 'sound_error' => null]);
    }

    public function failed(?Throwable $e): void
    {
        $this->episode->fresh()?->update(['sound_status' => 'failed', 'sound_error' => mb_substr($e?->getMessage() ?: 'Chọn âm thanh thất bại.', 0, 500)]);
    }
}
