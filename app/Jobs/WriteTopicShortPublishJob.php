<?php

namespace App\Jobs;

use App\Models\TopicShort;
use App\Services\Claude\AiUsage;
use App\Services\Script\ScriptWriterException;
use App\Services\TopicShort\CoverImage;
use App\Services\TopicShort\PublishWriter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/** Claude viết tiêu đề + mô tả + chữ ảnh bìa, rồi ghép ảnh bìa từ ảnh cảnh (nếu chưa có ảnh bìa). */
class WriteTopicShortPublishJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public TopicShort $short) {}

    public function handle(): void
    {
        $short = $this->short->fresh();
        if ($short === null || ($short->publish['status'] ?? null) !== 'writing') {
            return;
        }

        try {
            $pack = AiUsage::withContext(['user_id' => $short->user_id, 'subject' => "topic_short:{$short->id}"], fn () => PublishWriter::write($short));
        } catch (ScriptWriterException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 120);

                return;
            }
            $this->fail($e);

            return;
        }

        $short->update(['publish' => [...$pack, 'title' => $pack['titles'][0], 'status' => 'ready', 'error' => null]]);
        // Ảnh bìa bạn tự tải lên thì giữ nguyên.
        if (! ($short->cover['uploaded'] ?? false) && ($scene = CoverImage::defaultScene($short))) {
            try {
                $chosen = ($id = $short->cover['scene_id'] ?? null) ? $short->scenes()->find($id) : null;
                CoverImage::make($short, $chosen ?? $scene, $pack['cover_text']);
            } catch (RuntimeException) {
                // Ảnh bìa lỗi không làm hỏng tiêu đề / mô tả; người dùng bấm tạo lại ảnh bìa.
            }
        }
    }

    public function failed(?Throwable $e): void
    {
        $short = $this->short->fresh();
        $short?->update(['publish' => [...($short->publish ?? []), 'status' => 'failed', 'error' => mb_substr($e?->getMessage() ?: 'Viết tiêu đề thất bại.', 0, 500)]]);
    }
}
