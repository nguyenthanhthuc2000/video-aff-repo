<?php

namespace App\Jobs;

use App\Models\Episode;
use App\Services\Claude\AiUsage;
use App\Services\Film\EpisodeWriter;
use App\Services\Script\ScriptWriterException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Claude viết dàn ý tập (các phân đoạn), xoá shot cũ rồi viết lần lượt từng phân đoạn (WriteSequenceJob). */
class WriteEpisodeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /** @param  string  $run  Mã lần viết: bấm viết lại thì các job của lần cũ tự dừng. */
    public function __construct(public Episode $episode, public string $run) {}

    public function handle(): void
    {
        $episode = $this->episode->fresh();
        if ($episode === null || $episode->status !== 'writing' || ($episode->outline['run'] ?? null) !== $this->run) {
            return;
        }

        try {
            $sequences = AiUsage::withContext(
                ['user_id' => $episode->series->user_id, 'subject' => "series:{$episode->series_id}"],
                fn () => EpisodeWriter::outline($episode),
            );
        } catch (ScriptWriterException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 120);

                return;
            }
            $this->failed($e);

            return;
        }

        $episode->shots()->delete();
        $episode->update(['outline' => ['run' => $this->run, 'sequences' => $sequences, 'written' => 0], 'error' => null]);
        WriteSequenceJob::dispatch($episode, $this->run, 0);
    }

    public function failed(?Throwable $e): void
    {
        $episode = $this->episode->fresh();
        if ($episode && ($episode->outline['run'] ?? null) === $this->run) {
            $episode->update(['status' => 'failed', 'error' => mb_substr('Chưa viết được dàn ý tập: '.($e?->getMessage() ?? 'lỗi không rõ'), 0, 500)]);
        }
    }
}
