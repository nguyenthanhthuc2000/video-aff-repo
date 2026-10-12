<?php

namespace App\Jobs;

use App\Models\Shot;
use App\Services\Claude\AiUsage;
use App\Services\Film\ShotPrompt;
use App\Services\Film\ShotRewriter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Claude viết lại cảnh của shot bị Muse từ chối, lưu vào shot rồi đưa Muse vẽ lại. */
class RewriteShotJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    /** @var list<int> */
    public array $backoff = [30];

    public function __construct(public Shot $shot, public ?string $reason, public ?string $notes = null) {}

    public function handle(): void
    {
        $shot = $this->shot->fresh();
        if ($shot === null || $shot->image_status !== 'pending' || $shot->request_id !== null) {
            return;
        }

        $series = $shot->episode->series;
        $new = AiUsage::withContext(
            ['user_id' => $series->user_id, 'subject' => "series:{$series->id}"],
            fn () => ShotRewriter::rewrite($shot, $this->reason),
        );
        $shot->update($new);

        GenerateShotImageJob::dispatch($shot, ShotPrompt::build($shot->fresh(), $this->notes));
    }

    public function failed(?Throwable $e): void
    {
        $shot = $this->shot->fresh();
        $shot?->update(['image_status' => 'failed', 'error' => mb_substr('Claude chưa viết lại được cảnh: '.($e?->getMessage() ?: 'lỗi không rõ'), 0, 500)]);
        $shot?->episode->syncKeyframeStatus();
    }
}
