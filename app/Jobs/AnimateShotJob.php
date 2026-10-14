<?php

namespace App\Jobs;

use App\Models\Shot;
use App\Services\Film\ShotAudio;
use App\Services\Film\ShotClipPrompt;
use App\Services\Film\ShotVideo;
use App\Services\Film\VoiceMatch;
use App\Services\Image\ImageGeneratorException;
use App\Services\Image\MuseTask;
use App\Services\Video\ClipGenerator;
use App\Support\CreditPricing;
use App\Support\Credits;
use App\Support\Media;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Video của một shot: đọc lời thoại bằng giọng cố định của nhân vật (Kokoro), gửi Muse khung hình + tiếng thoại để làm clip khớp môi,
 * rồi ghép clip với tiếng thoại (gửi rồi hỏi lại theo mã, không giữ hàng đợi).
 */
class AnimateShotJob implements ShouldQueue
{
    use Queueable;

    public const POLL_SECONDS = 15;

    public int $tries = 0;

    public int $timeout = 600;

    public function __construct(public Shot $shot, public ?string $notes = null) {}

    public function retryUntil(): DateTimeInterface
    {
        // Cả tập nhiều shot chờ lượt Muse.
        return now()->addHours(8);
    }

    public static function price(): int
    {
        return CreditPricing::settings()['steps']['clip_style'];
    }

    public function handle(): void
    {
        $shot = $this->shot->fresh();
        if ($shot === null || $shot->clip_status !== 'pending') {
            return;
        }
        // Đã có clip Muse: đổi giọng tiếng Muse sang giọng nhân vật (khớp khẩu hình) rồi ghép video. Không gọi Muse, không tốn Credits.
        if ($shot->clip_stage === 'sync') {
            $this->sync($shot);

            return;
        }
        /** @var ClipGenerator $generator */
        $generator = app('film.clip');

        try {
            if (! MuseTask::isCode($shot->clip_request_id)) {
                if ($shot->image_path === null) {
                    $this->fail(new RuntimeException('Shot chưa có khung hình.'));

                    return;
                }
                if ($message = Credits::shortfall($shot->episode->series->user, self::price(), 'làm clip shot')) {
                    $this->fail(new RuntimeException($message));

                    return;
                }
                // Lời thoại đọc trước (có thể kéo dài shot), Muse khớp môi theo đúng tiếng này. Đọc biểu cảm chạy nền: chờ xong.
                if (! ShotAudio::advance($shot)) {
                    $this->release(20);

                    return;
                }
                $shot->refresh();
                // MV ca nhạc: gửi đoạn bài hát của shot (hát nhép); phim thường: tiếng thoại.
                $audio = $shot->episode->series->musicVideo() ? ShotVideo::songAttachment($shot) : ShotVideo::dialogueAttachment($shot);
                try {
                    $code = $generator->start($shot->image_path, ShotClipPrompt::build($shot, $this->notes), null, $audio);
                } finally {
                    Media::delete($audio);
                }
                $shot->update(['clip_request_id' => $code, 'clip_stage' => 'muse']);
                $this->release(30);

                return;
            }

            $clip = $generator->poll($shot->clip_request_id);
            if ($clip === null) {
                $this->release(self::POLL_SECONDS);

                return;
            }
        } catch (ImageGeneratorException $e) {
            if ($e->busy) {
                $this->release(30);

                return;
            }
            if ($e->retryable && Cache::increment("muse-retries:shot-clip:{$shot->id}") <= 2) {
                $shot->update(['clip_request_id' => null]);
                $this->release(60);

                return;
            }
            $this->fail($e);

            return;
        } catch (RuntimeException $e) {
            // Giọng đọc / ffmpeg lỗi: thử lại cũng vậy.
            $this->fail($e);

            return;
        }

        $episode = $shot->episode;
        $oldClip = $shot->clip_path;
        $clipPath = "series/{$episode->series_id}/episodes/{$episode->id}/shot-{$shot->id}-clip-{$shot->clip_request_id}.{$clip['extension']}";
        Media::disk()->put($clipPath, $clip['contents']);
        $shot->update(['clip_path' => $clipPath, 'clip_stage' => 'sync', 'clip_request_id' => null]);
        if ($oldClip !== null && $oldClip !== $clipPath) {
            Media::delete($oldClip);
        }
        Credits::charge(
            $episode->series->user_id,
            self::price(),
            'clip',
            "shot-clip:{$shot->id}:{$shot->clip_request_id}",
            "Phim {$episode->series->title}: clip tập {$episode->number}",
        );

        $this->sync($shot->fresh());
    }

    /** Khớp tiếng (đổi giọng tiếng Muse, chạy nền ở dịch vụ expressive) rồi ghép clip + tiếng thành video của shot. */
    private function sync(Shot $shot): void
    {
        try {
            if (! VoiceMatch::advance($shot)) {
                $this->release(15);

                return;
            }
            $video = ShotVideo::mux($shot->fresh());
        } catch (RuntimeException $e) {
            $this->fail($e);

            return;
        }
        $old = $shot->video_path;
        $shot->update(['video_path' => $video, 'clip_status' => 'ready', 'clip_stage' => null, 'clip_request_id' => null, 'clip_error' => null]);
        if ($old !== null && $old !== $video) {
            Media::delete($old);
        }
        $shot->episode->syncStatus();
    }

    public function failed(?Throwable $e): void
    {
        $shot = $this->shot->fresh();
        $shot?->update(['clip_status' => 'failed', 'clip_stage' => null, 'clip_request_id' => null, 'clip_error' => mb_substr($e?->getMessage() ?: 'Làm clip thất bại.', 0, 500)]);
        $shot?->episode->syncStatus();
    }
}
