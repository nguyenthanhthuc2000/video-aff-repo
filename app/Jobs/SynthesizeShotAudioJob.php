<?php

namespace App\Jobs;

use App\Models\Shot;
use App\Services\Film\ShotAudio;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** Đọc lời thoại một shot bằng giọng cố định của nhân vật (Kokoro, miễn phí) để nghe thử trước khi làm clip. */
class SynthesizeShotAudioJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    /** @var list<int> */
    public array $backoff = [20];

    public function __construct(public Shot $shot) {}

    public function handle(): void
    {
        $shot = $this->shot->fresh();
        // Đang làm clip thì job clip tự đọc, không đọc chồng.
        if ($shot === null || $shot->clip_status === 'pending') {
            return;
        }
        try {
            ShotAudio::ensure($shot);
        } catch (RuntimeException $e) {
            Log::warning('shot_audio.failed', ['shot_id' => $shot->id, 'error' => $e->getMessage()]);
            $shot->update(['clip_error' => mb_substr($e->getMessage(), 0, 500)]);
        }
    }
}
