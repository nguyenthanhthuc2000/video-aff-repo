<?php

namespace App\Jobs;

use App\Models\VideoProject;
use App\Services\Claude\AiUsage;
use App\Services\Script\PostCaptions;
use App\Services\Script\ScriptWriterException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Viết đề xuất caption đăng video (bán hàng / câu view) sau khi có kịch bản; lỗi thì chỉ báo ở mục đề xuất, không ảnh hưởng dự án. */
class GeneratePostCaptionsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public VideoProject $project) {}

    public function handle(): void
    {
        $project = $this->project->fresh();
        if ($project === null || $project->script === null) {
            return;
        }

        try {
            $captions = AiUsage::withContext(
                ['user_id' => $project->user_id, 'video_project_id' => $project->id],
                fn () => PostCaptions::write($project),
            );
        } catch (ScriptWriterException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 120);

                return;
            }
            $this->failed($e);

            return;
        }

        $project->update(['post_captions' => $captions, 'post_captions_status' => 'ready']);
    }

    public function failed(?Throwable $e): void
    {
        $this->project->fresh()?->update([
            'post_captions_status' => 'failed',
            'post_captions' => ['error' => mb_substr('Chưa viết được đề xuất caption: '.($e?->getMessage() ?? 'lỗi không rõ'), 0, 300)],
        ]);
    }
}
