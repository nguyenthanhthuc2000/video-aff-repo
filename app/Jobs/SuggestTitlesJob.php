<?php

namespace App\Jobs;

use App\Models\Episode;
use App\Services\Claude\AiUsage;
use App\Services\Film\EpisodeTitler;
use App\Services\Script\ScriptWriterException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Claude gợi ý vài tiêu đề video (kèm chữ cho ảnh bìa) cho tập. */
class SuggestTitlesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public Episode $episode) {}

    public function handle(): void
    {
        $episode = $this->episode->fresh();
        if ($episode === null || $episode->title_status !== 'suggesting') {
            return;
        }
        try {
            $ideas = AiUsage::withContext(['user_id' => $episode->series->user_id, 'subject' => "series:{$episode->series_id}"], fn () => EpisodeTitler::suggest($episode));
        } catch (ScriptWriterException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 120);

                return;
            }
            $this->fail($e);

            return;
        }
        $episode->update(['title_ideas' => $ideas, 'title_status' => 'ready', 'title_error' => null]);
    }

    public function failed(?Throwable $e): void
    {
        $this->episode->fresh()?->update(['title_status' => 'failed', 'title_error' => mb_substr('Claude chưa gợi ý được tiêu đề: '.($e?->getMessage() ?: 'lỗi không rõ'), 0, 500)]);
    }
}
