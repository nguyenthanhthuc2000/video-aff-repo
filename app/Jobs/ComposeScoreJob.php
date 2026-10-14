<?php

namespace App\Jobs;

use App\Models\Episode;
use App\Services\Claude\AiUsage;
use App\Services\Film\EpisodeScore;
use App\Services\Film\ScoreComposer;
use App\Services\Script\ScriptWriterException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/** Claude sáng tác nhạc riêng cho tập, rồi phát thử từng đoạn (miễn phí, trên máy) để nghe trước khi ghép. */
class ComposeScoreJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public Episode $episode) {}

    /** @throws ScriptWriterException|RuntimeException */
    public static function compose(Episode $episode): array
    {
        $score = AiUsage::withContext(['user_id' => $episode->series->user_id, 'subject' => "series:{$episode->series_id}"], fn () => ScoreComposer::compose($episode));

        return EpisodeScore::withPreviews($episode, $score);
    }

    public function handle(): void
    {
        $episode = $this->episode->fresh();
        if ($episode === null || $episode->score_status !== 'composing') {
            return;
        }
        try {
            $score = self::compose($episode);
        } catch (ScriptWriterException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 120);

                return;
            }
            $this->fail($e);

            return;
        } catch (RuntimeException $e) {
            $this->fail($e);

            return;
        }
        $episode->update(['score' => $score, 'score_status' => 'ready', 'score_error' => null]);
    }

    public function failed(?Throwable $e): void
    {
        $this->episode->fresh()?->update(['score_status' => 'failed', 'score_error' => mb_substr($e?->getMessage() ?: 'Sáng tác nhạc thất bại.', 0, 500)]);
    }
}
