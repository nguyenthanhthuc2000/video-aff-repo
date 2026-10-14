<?php

namespace App\Jobs;

use App\Http\Controllers\SongDraftController;
use App\Models\SongDraft;
use App\Services\MiniMax\MiniMaxSongGenerator;
use App\Services\Music\FalSongGenerator;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/** Tạo bài hát mới qua fal (lời + prompt phong cách của bài đã sáng tác), xong thì lưu vào thư viện Âm thanh. Gửi rồi hỏi lại. */
class GenerateSongJob implements ShouldQueue
{
    use Queueable;

    public const POLL_SECONDS = 15;

    public int $timeout = 300;

    public function __construct(public SongDraft $draft) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public function handle(): void
    {
        $draft = $this->draft->fresh();
        if ($draft === null || $draft->music_status !== 'pending') {
            return;
        }
        $generator = $draft->music_provider === 'minimax-direct' ? new MiniMaxSongGenerator : FalSongGenerator::for((string) $draft->music_provider);
        try {
            if ($draft->music_request_id === null) {
                $draft->update(['music_request_id' => $generator->start($draft->song['lyrics'], $draft->song['style_prompt'], $draft->targetSeconds())]);
                $this->release(self::POLL_SECONDS);

                return;
            }
            $song = $generator->poll($draft->music_request_id);
        } catch (RuntimeException $e) {
            $this->fail($e);

            return;
        }
        if ($song === null) {
            $this->release(self::POLL_SECONDS);

            return;
        }
        SongDraftController::attach($draft, $song['contents'], $song['extension'], null, $draft->music_provider);
    }

    public function failed(?Throwable $e): void
    {
        $this->draft->fresh()?->update(['music_status' => 'failed', 'music_error' => mb_substr($e?->getMessage() ?: 'Tạo nhạc thất bại.', 0, 500)]);
    }
}
