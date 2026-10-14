<?php

namespace App\Jobs;

use App\Models\EnglishLesson;
use App\Services\Claude\AiUsage;
use App\Services\English\LessonWriter;
use App\Services\English\ModelVoice;
use App\Services\Script\ScriptWriterException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/** Claude soạn bài của ngày, rồi Kokoro đọc giọng mẫu từng câu (miễn phí). */
class WriteEnglishLessonJob implements ShouldQueue
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
        if ($lesson === null || $lesson->status !== 'writing') {
            return;
        }
        try {
            $content = AiUsage::withContext(['user_id' => $lesson->user_id, 'subject' => "english:{$lesson->id}"], fn () => LessonWriter::write($lesson));
        } catch (ScriptWriterException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 120);

                return;
            }
            $this->fail($e);

            return;
        }
        try {
            if ($lesson->kind === 'sentences') {
                $content['audio'] = ModelVoice::speak($lesson, array_column($content['sentences'], 'en'), 'model');
            }
        } catch (RuntimeException $e) {
            $this->fail($e);

            return;
        }
        ModelVoice::forget($lesson->content['audio'] ?? null);
        $lesson->update(['content' => $content, 'status' => 'ready', 'error' => null]);
    }

    public function failed(?Throwable $e): void
    {
        $this->lesson->fresh()?->update(['status' => 'failed', 'error' => mb_substr($e?->getMessage() ?: 'Soạn bài thất bại.', 0, 500)]);
    }
}
