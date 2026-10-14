<?php

namespace App\Jobs;

use App\Models\EnglishLesson;
use App\Services\Claude\AiUsage;
use App\Services\English\ModelVoice;
use App\Services\English\SpeakingCoach;
use App\Services\Script\ScriptWriterException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/** Claude sửa bài nói 30 giây (lần nói tốt nhất), giọng mẫu đọc các câu đúng. */
class CorrectSpeakingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public EnglishLesson $lesson) {}

    public function handle(): void
    {
        $lesson = $this->lesson->fresh();
        $recording = $lesson?->bestRecordings()[0] ?? null;
        if ($lesson === null || ($lesson->feedback['status'] ?? null) !== 'correcting' || $recording === null) {
            return;
        }
        try {
            $feedback = AiUsage::withContext(['user_id' => $lesson->user_id, 'subject' => "english:{$lesson->id}"], fn () => SpeakingCoach::correct($lesson, (string) $recording->transcript));
            $feedback['audio'] = ModelVoice::speak($lesson, array_column($feedback['corrections'], 'better'), 'better');
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
        ModelVoice::forget($lesson->feedback['audio'] ?? null);
        $lesson->update(['feedback' => [...$feedback, 'status' => 'ready', 'recording_id' => $recording->id]]);
    }

    public function failed(?Throwable $e): void
    {
        $lesson = $this->lesson->fresh();
        $lesson?->update(['feedback' => [...($lesson->feedback ?? []), 'status' => 'failed', 'error' => mb_substr($e?->getMessage() ?: 'Sửa bài nói thất bại.', 0, 500)]]);
    }
}
