<?php

namespace App\Jobs;

use App\Models\Shot;
use App\Services\Claude\AiUsage;
use App\Services\Film\ShotRewriter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Muse từ chối làm clip: Claude viết lại chuyển động của shot (giữ khung hình), lưu vào shot rồi đưa Muse làm lại clip. */
class RewriteShotClipJob implements ShouldQueue
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
        if ($shot === null || $shot->clip_status !== 'pending' || $shot->clip_stage !== 'rewrite') {
            return;
        }

        $series = $shot->episode->series;
        $motion = AiUsage::withContext(
            ['user_id' => $series->user_id, 'subject' => "series:{$series->id}"],
            fn () => ShotRewriter::rewriteMotion($shot, $this->reason),
        );
        $shot->update(['motion' => $motion, 'clip_stage' => 'voice']);

        AnimateShotJob::dispatch($shot, $this->notes);
    }

    public function failed(?Throwable $e): void
    {
        $shot = $this->shot->fresh();
        $shot?->update(['clip_status' => 'failed', 'clip_stage' => null, 'clip_error' => mb_substr('Claude chưa viết lại được chuyển động: '.($e?->getMessage() ?: 'lỗi không rõ'), 0, 500)]);
        $shot?->episode->syncStatus();
    }
}
