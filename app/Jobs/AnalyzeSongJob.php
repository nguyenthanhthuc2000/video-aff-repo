<?php

namespace App\Jobs;

use App\Models\AudioTrack;
use App\Services\Claude\AiUsage;
use App\Services\Film\SongAnalysis;
use App\Services\Script\ScriptWriterException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Claude phân tích bài hát trong thư viện cho MV (thể loại, nhịp, không khí, cấu trúc, chủ đề, lời), lưu vào song_info.analysis. */
class AnalyzeSongJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public AudioTrack $track) {}

    public function handle(): void
    {
        $track = $this->track->fresh();
        if ($track === null) {
            return;
        }
        $track->update(['song_info' => [...($track->song_info ?? []), 'analysis_status' => 'pending', 'analysis_error' => null]]);
        try {
            $analysis = AiUsage::withContext(['user_id' => $track->user_id, 'subject' => "audio:{$track->id}"], fn () => SongAnalysis::analyze($track));
        } catch (ScriptWriterException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 120);

                return;
            }
            $this->failed($e);

            return;
        }
        $track->update([
            'song_info' => [...($track->fresh()->song_info ?? []), 'analysis' => $analysis, 'analysis_status' => 'ready', 'analysis_error' => null],
            // Chưa có lời thì dùng lời Claude đã chia đoạn.
            'lyrics' => $track->lyrics ?: ($analysis['lyrics'] ?: null),
        ]);
    }

    public function failed(?Throwable $e): void
    {
        $track = $this->track->fresh();
        $track?->update(['song_info' => [...($track->song_info ?? []), 'analysis_status' => 'failed', 'analysis_error' => mb_substr($e?->getMessage() ?: 'Claude chưa phân tích được bài hát.', 0, 300)]]);
    }
}
