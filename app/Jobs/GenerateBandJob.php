<?php

namespace App\Jobs;

use App\Models\VirtualBand;
use App\Services\Claude\AiUsage;
use App\Services\Film\BandWriter;
use App\Services\Script\ScriptWriterException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Claude nghĩ ra ban nhạc ảo (tên, câu chuyện, thành viên) cho series Shorts nhạc. */
class GenerateBandJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public VirtualBand $band, public ?string $notes = null) {}

    public function handle(): void
    {
        $band = $this->band->fresh();
        if ($band === null || $band->status !== 'writing') {
            return;
        }
        try {
            $data = AiUsage::withContext(['user_id' => $band->user_id, 'subject' => "band:{$band->id}"], fn () => BandWriter::write($band, $this->notes));
        } catch (ScriptWriterException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release(60);

                return;
            }
            $this->failed($e);

            return;
        }
        $band->update([...$data, 'status' => 'ready', 'error' => null]);
    }

    public function failed(?Throwable $e): void
    {
        $this->band->fresh()?->update(['status' => 'failed', 'error' => mb_substr($e?->getMessage() ?: 'Chưa tạo được ban nhạc.', 0, 500)]);
    }
}
