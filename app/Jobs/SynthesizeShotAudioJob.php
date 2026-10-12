<?php

namespace App\Jobs;

use App\Models\Shot;
use App\Services\Film\ShotAudio;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** Đọc lời thoại một shot bằng giọng cố định của nhân vật (Kokoro hoặc Chatterbox biểu cảm, miễn phí) để nghe thử trước khi làm clip. */
class SynthesizeShotAudioJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $timeout = 300;

    public function retryUntil(): \DateTimeInterface
    {
        // Đọc biểu cảm: các shot xếp hàng lần lượt ở dịch vụ Chatterbox, cả tập có thể mất vài giờ.
        return now()->addHours(8);
    }

    public function __construct(public Shot $shot) {}

    public function handle(): void
    {
        $shot = $this->shot->fresh();
        // Đang làm clip thì job clip tự đọc, không đọc chồng.
        if ($shot === null || $shot->clip_status === 'pending') {
            $shot && ShotAudio::clearPending($shot);

            return;
        }
        try {
            if (! ShotAudio::advance($shot)) {
                $this->release(20);

                return;
            }
        } catch (RuntimeException $e) {
            Log::warning('shot_audio.failed', ['shot_id' => $shot->id, 'error' => $e->getMessage()]);
            ShotAudio::setError($shot, $e->getMessage());
        }
        ShotAudio::clearPending($shot);
    }
}
