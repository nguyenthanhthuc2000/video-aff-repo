<?php

namespace App\Jobs;

use App\Models\Episode;
use App\Services\Claude\AiUsage;
use App\Services\Film\EpisodeTitler;
use App\Services\Film\EpisodeWriter;
use App\Services\Film\MusicVideoWriter;
use App\Services\Script\ScriptWriterException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
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

        if ($episode->series->silent()) {
            $this->writeShort($episode);

            return;
        }
        if ($episode->series->musicVideo()) {
            $this->writeMusicVideo($episode);

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

    /** Truyện ngắn không thoại: một lượt viết trọn gói (shot + gói sản xuất / đăng bài), một phân đoạn, cắt thẳng giữa các shot. */
    private function writeShort(Episode $episode): void
    {
        try {
            $result = AiUsage::withContext(
                ['user_id' => $episode->series->user_id, 'subject' => "series:{$episode->series_id}"],
                fn () => EpisodeWriter::short($episode),
            );
        } catch (ScriptWriterException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 120);

                return;
            }
            $this->failed($e);

            return;
        }

        DB::transaction(function () use ($episode, $result) {
            $episode->shots()->delete();
            foreach ($result['shots'] as $i => $shot) {
                $episode->shots()->create([...$shot, 'sequence' => 1, 'position' => $i + 1, 'image_status' => 'none', 'clip_status' => 'none']);
            }
            $seconds = (int) round(array_sum(array_column($result['shots'], 'duration_ms')) / 1000);
            $episode->update([
                'outline' => ['run' => $this->run, 'written' => 1, 'sequences' => [[
                    'title' => $result['package']['title'], 'summary' => $result['package']['logline'] ?: $episode->synopsis, 'location' => '', 'characters' => [], 'seconds' => $seconds,
                ]]],
                'package' => $result['package'],
                // Tên tập còn là chữ tạm của file mẫu: dùng tiêu đề Claude đặt trong gói đăng bài.
                ...(EpisodeTitler::placeholder($episode->title) && filled($result['package']['title']) ? ['title' => $result['package']['title']] : []),
                // Âm thanh hiệu ứng chọn theo shot cũ (đã xoá) không còn dùng được.
                'sound_plan' => null,
                'status' => 'storyboard',
                'duration_ms' => $seconds * 1000,
                'error' => null,
            ]);
        });
    }

    /** MV ca nhạc: một lượt viết cả phân cảnh theo bài hát, shot nối liền phủ đúng đoạn bài hát (cắt thẳng giữa các shot). */
    private function writeMusicVideo(Episode $episode): void
    {
        try {
            $result = AiUsage::withContext(
                ['user_id' => $episode->series->user_id, 'subject' => "series:{$episode->series_id}"],
                fn () => MusicVideoWriter::write($episode),
            );
        } catch (ScriptWriterException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 120);

                return;
            }
            $this->failed($e);

            return;
        }

        DB::transaction(function () use ($episode, $result) {
            $episode->shots()->delete();
            foreach ($result['shots'] as $i => $shot) {
                $episode->shots()->create([...$shot, 'sequence' => 1, 'position' => $i + 1, 'image_status' => 'none', 'clip_status' => 'none']);
            }
            $ms = array_sum(array_column($result['shots'], 'duration_ms'));
            $episode->update([
                'outline' => ['run' => $this->run, 'written' => 1, 'sequences' => [[
                    'title' => $result['package']['title'], 'summary' => $result['package']['logline'] ?: $episode->synopsis, 'location' => '', 'characters' => [], 'seconds' => (int) round($ms / 1000),
                ]]],
                'package' => $result['package'],
                'sound_plan' => null,
                'status' => 'storyboard',
                'duration_ms' => $ms,
                'error' => null,
            ]);
        });
    }

    public function failed(?Throwable $e): void
    {
        $episode = $this->episode->fresh();
        if ($episode && ($episode->outline['run'] ?? null) === $this->run) {
            $episode->update(['status' => 'failed', 'error' => mb_substr('Chưa viết được dàn ý tập: '.($e?->getMessage() ?? 'lỗi không rõ'), 0, 500)]);
        }
    }
}
