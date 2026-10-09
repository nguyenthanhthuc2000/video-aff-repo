<?php

namespace App\Jobs;

use App\Enums\VideoProjectStatus;
use App\Models\VideoProject;
use App\Services\Script\ScriptWriter;
use App\Services\Script\ScriptWriterException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
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

        DB::transaction(function () use ($project, $script) {
            $project->scenes()->delete();

            foreach ($script['scenes'] as $i => $scene) {
                $project->scenes()->create([...$scene, 'position' => $i + 1]);
            }

            $project->update([
                'script' => [
                    'hooks' => $script['hooks'],
                    'caption' => $script['caption'],
                    'hashtags' => $script['hashtags'],
                ],
                'selected_hook' => 0,
                'status' => VideoProjectStatus::ScriptReady,
                'error' => null,
            ]);
        });
    }

    public function failed(?Throwable $e): void
    {
        $this->project->markFailed('Tạo kịch bản thất bại: '.($e?->getMessage() ?? 'lỗi không rõ'));
    }
}
