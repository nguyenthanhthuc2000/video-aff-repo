<?php

namespace App\Jobs;

use App\Models\TopicShort;
use App\Services\Claude\AiUsage;
use App\Services\Script\ScriptWriterException;
use App\Services\TopicShort\TopicResearcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Claude tìm tư liệu trên web cho chủ đề, lưu bản tư liệu chờ người dùng duyệt trước khi viết kịch bản. */
class ResearchTopicJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    // Nhiều lượt tìm / đọc trang web.
    public int $timeout = 1000;

    /** @var list<int> */
    public array $backoff = [60, 180];

    public function __construct(public TopicShort $short) {}

    public function handle(): void
    {
        $short = $this->short->fresh();
        if ($short === null || $short->status !== 'researching') {
            return;
        }

        try {
            $research = AiUsage::withContext(['user_id' => $short->user_id, 'subject' => "topic_short:{$short->id}"], fn () => TopicResearcher::research($short));
        } catch (ScriptWriterException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 180);

                return;
            }
            $this->fail($e);

            return;
        }

        $short->update(['research' => $research, 'status' => 'research_ready', 'error' => null]);
    }

    public function failed(?Throwable $e): void
    {
        $this->short->fresh()?->update(['status' => 'failed', 'error' => mb_substr($e?->getMessage() ?: 'Tìm tư liệu thất bại.', 0, 500)]);
    }
}
