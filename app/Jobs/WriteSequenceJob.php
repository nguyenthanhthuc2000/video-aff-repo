<?php

namespace App\Jobs;

use App\Models\Episode;
use App\Models\Shot;
use App\Services\Claude\AiUsage;
use App\Services\Film\EpisodeWriter;
use App\Services\Script\ScriptWriterException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Claude viết shot của một phân đoạn (kèm vài shot cuối của phân đoạn trước), xong thì viết phân đoạn kế tiếp. */
class WriteSequenceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public Episode $episode, public string $run, public int $index) {}

    public function handle(): void
    {
        $episode = $this->episode->fresh();
        $outline = $episode?->outline ?? [];
        if ($episode === null || $episode->status !== 'writing' || ($outline['run'] ?? null) !== $this->run || ! isset($outline['sequences'][$this->index])) {
            return;
        }

        $characters = $episode->series->characters()->get();
        $previous = $episode->shots()->reorder()->orderByDesc('sequence')->orderByDesc('position')->limit(4)->get()->reverse()
            ->map(fn (Shot $shot) => EpisodeWriter::describe($shot->only(['camera', 'action', 'dialogue']), $characters))->values()->all();

        try {
            $shots = AiUsage::withContext(
                ['user_id' => $episode->series->user_id, 'subject' => "series:{$episode->series_id}"],
                fn () => EpisodeWriter::sequence($episode, $outline['sequences'], $this->index, $previous),
            );
        } catch (ScriptWriterException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 120);

                return;
            }
            $this->failed($e);

            return;
        }

        $last = $this->index + 1 >= count($outline['sequences']);
        DB::transaction(function () use ($episode, $shots, $outline, $last) {
            // Chạy lại sau lỗi: thay shot của phân đoạn này.
            $episode->shots()->where('sequence', $this->index + 1)->delete();
            foreach ($shots as $i => $shot) {
                // image_status "none": chưa vẽ ("pending" là đang chờ Muse).
                $episode->shots()->create([...$shot, 'sequence' => $this->index + 1, 'position' => $i + 1, 'image_status' => 'none', 'clip_status' => 'none']);
            }
            $episode->update([
                'outline' => [...$outline, 'written' => $this->index + 1],
                ...($last ? ['status' => 'storyboard', 'duration_ms' => (int) $episode->shots()->sum('duration_ms')] : []),
            ]);
        });

        if (! $last) {
            self::dispatch($episode, $this->run, $this->index + 1);
        }
    }

    public function failed(?Throwable $e): void
    {
        $episode = $this->episode->fresh();
        if ($episode && ($episode->outline['run'] ?? null) === $this->run) {
            $episode->update([
                'status' => 'failed',
                'error' => mb_substr('Chưa viết được phân đoạn '.($this->index + 1).': '.($e?->getMessage() ?? 'lỗi không rõ'), 0, 500),
            ]);
        }
    }
}
