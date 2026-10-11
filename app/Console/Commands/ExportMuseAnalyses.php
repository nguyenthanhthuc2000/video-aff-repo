<?php

namespace App\Console\Commands;

use App\Models\MuseAnalysis;
use Illuminate\Console\Command;

/** Xuất kho phân tích của Muse ra JSONL (mỗi dòng một lần phân tích) để xây bộ skill viết prompt. */
class ExportMuseAnalyses extends Command
{
    protected $signature = 'muse:export {path=storage/app/muse-analyses.jsonl : File JSONL} {--all : Kèm cả lần lỗi / đang chạy}';

    protected $description = 'Xuất các lần Muse phân tích (prompt, câu trả lời, kết quả, đầu vào) ra JSONL';

    public function handle(): int
    {
        $path = str_starts_with($this->argument('path'), '/') ? $this->argument('path') : base_path($this->argument('path'));
        $handle = fopen($path, 'w');
        $count = 0;

        MuseAnalysis::query()
            ->when(! $this->option('all'), fn ($query) => $query->where('status', 'ready'))
            ->orderBy('id')
            ->each(function (MuseAnalysis $analysis) use ($handle, &$count) {
                fwrite($handle, json_encode([
                    'id' => $analysis->id,
                    'kind' => $analysis->kind,
                    'status' => $analysis->status,
                    'video_project_id' => $analysis->video_project_id,
                    'context' => $analysis->context,
                    'prompt' => $analysis->prompt,
                    'reply' => $analysis->reply,
                    'result' => $analysis->result,
                    'error' => $analysis->error,
                    'created_at' => $analysis->created_at?->toIso8601String(),
                    'completed_at' => $analysis->completed_at?->toIso8601String(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
                $count++;
            });
        fclose($handle);

        $this->info("Đã xuất {$count} lần phân tích ra {$path}.");

        return self::SUCCESS;
    }
}
