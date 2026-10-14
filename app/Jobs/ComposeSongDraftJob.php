<?php

namespace App\Jobs;

use App\Models\SongDraft;
use App\Services\Audio\YoutubeReference;
use App\Services\Claude\AiUsage;
use App\Services\Film\LyricsOverlap;
use App\Services\Film\SongWriter;
use App\Services\Script\ScriptWriterException;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Sáng tác bài mới từ link YouTube tham khảo: đọc thông tin + phụ đề (dịch vụ audio, không tải nhạc) → Claude phân tích hồ sơ tham khảo
 * → Claude viết bài mới → kiểm tra trùng lời (quá giống thì viết lại một lần, vẫn giống thì cảnh báo). Bấm "Viết lại" thì chỉ chạy bước viết.
 */
class ComposeSongDraftJob implements ShouldQueue
{
    use Queueable;

    public const POLL_SECONDS = 10;

    public int $timeout = 600;

    public function __construct(public SongDraft $draft) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public function handle(YoutubeReference $youtube): void
    {
        $draft = $this->draft->fresh();
        if ($draft === null || ! in_array($draft->status, SongDraft::BUSY, true)) {
            return;
        }
        $key = "song-draft-ref:{$draft->id}";

        try {
            // Bài của ban nhạc ảo không có link tham khảo: viết luôn theo ban nhạc + chủ đề.
            if ($draft->status === 'fetching' && $draft->source_url === null) {
                $draft->update(['status' => 'writing']);
            }
            if ($draft->status === 'fetching') {
                if (! Cache::has($key)) {
                    Cache::put($key, $youtube->start($draft->source_url), now()->addHour());
                    $this->release(self::POLL_SECONDS);

                    return;
                }
                $reference = $youtube->poll((string) Cache::get($key));
                if ($reference === null) {
                    $this->release(self::POLL_SECONDS);

                    return;
                }
                Cache::forget($key);
                $draft->update(['reference' => $reference, 'status' => 'profiling']);
            }
            $context = ['user_id' => $draft->user_id, 'subject' => "song-draft:{$draft->id}"];
            if ($draft->status === 'profiling') {
                $draft->update(['profile' => AiUsage::withContext($context, fn () => SongWriter::profile($draft)), 'status' => 'writing']);
            }
            $reference = SongWriter::referenceLyrics($draft);
            $song = AiUsage::withContext($context, fn () => SongWriter::write($draft));
            $overlap = LyricsOverlap::check($song['lyrics'], $reference);
            if (LyricsOverlap::tooSimilar($overlap)) {
                $song = AiUsage::withContext($context, fn () => SongWriter::write($draft, $overlap['lines'] ?: ['(the overall wording)']));
                $overlap = LyricsOverlap::check($song['lyrics'], $reference);
            }
        } catch (ScriptWriterException $e) {
            if ($e->retryable && $this->attempts() < 3) {
                $this->release(60);

                return;
            }
            $this->fail($e);

            return;
        } catch (RuntimeException $e) {
            Cache::forget($key);
            $this->fail($e);

            return;
        }

        $draft->update(['song' => $song, 'overlap' => [...$overlap, 'too_similar' => LyricsOverlap::tooSimilar($overlap)], 'status' => 'ready', 'error' => null]);
    }

    public function failed(?Throwable $e): void
    {
        Cache::forget("song-draft-ref:{$this->draft->id}");
        $this->draft->fresh()?->update(['status' => 'failed', 'error' => mb_substr($e?->getMessage() ?: 'Chưa sáng tác được bài mới.', 0, 500)]);
    }
}
