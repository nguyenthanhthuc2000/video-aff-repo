<?php

namespace App\Jobs;

use App\Models\VideoProject;
use App\Services\Script\ScriptWriter;
use App\Services\Script\ScriptWriterException;
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

        try {
            $script = $writer->write($project);
        } catch (ScriptWriterException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 120);

                return;
            }

            $project->markFailed($e->getMessage());

            return;
        }

        $project->applyScript($script);
    }

    public function failed(?Throwable $e): void
    {
        $this->project->markFailed('Tạo kịch bản thất bại: '.($e?->getMessage() ?? 'lỗi không rõ'));
    }
}
