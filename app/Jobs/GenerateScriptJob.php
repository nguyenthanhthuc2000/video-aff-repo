<?php

namespace App\Jobs;

use App\Models\VideoProject;
use App\Services\Claude\AiUsage;
use App\Services\Script\ScriptWriter;
use App\Services\Script\ScriptWriterException;
use App\Support\CreditPricing;
use App\Support\Credits;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateScriptJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public VideoProject $project) {}

    public function handle(ScriptWriter $writer): void
    {
        $project = $this->project;

        // Trừ theo chi phí thật sau khi viết xong; trước đó kiểm tra số dư theo mức trung bình.
        if ($message = Credits::shortfall($project->user, CreditPricing::aiEstimates()['script'], 'viết kịch bản')) {
            $project->markFailed($message);

            return;
        }

        try {
            $script = AiUsage::withContext(
                ['user_id' => $project->user_id, 'video_project_id' => $project->id],
                fn () => $writer->write($project),
            );
        } catch (ScriptWriterException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 120);

                return;
            }

            $project->markFailed($e->getMessage());

            return;
        }

        $project->applyScript($script);
        $project->advanceAutopilot();
    }

    public function failed(?Throwable $e): void
    {
        $this->project->markFailed('Tạo kịch bản thất bại: '.($e?->getMessage() ?? 'lỗi không rõ'));
    }
}
