<?php

namespace App\Jobs;

use App\Models\TopicShortScene;
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

/** Muse làm video ngắn từ ảnh cảnh (gửi rồi hỏi lại theo mã); khi dựng, cảnh có video dùng video thay ảnh động. */
class GenerateTopicSceneClipJob implements ShouldQueue
{
    use Queueable;

    public const POLL_SECONDS = 15;

    public int $tries = 0;

    public int $timeout = 600;

    public function __construct(public TopicShortScene $scene)
    {
        $this->onQueue('images');
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(3);
    }

    public static function price(): int
    {
        return CreditPricing::settings()['steps']['clip_style'];
    }

    /** Mỗi lần bấm: đếm lại số lần Muse lỗi / bận từ đầu. */
    public static function resetCounters(TopicShortScene $scene): void
    {
        Cache::forget("muse-retries:topic-scene-clip:{$scene->id}");
        Cache::forget("muse-busy:topic-scene-clip:{$scene->id}");
    }

    public static function prompt(TopicShortScene $scene): string
    {
        $action = trim((string) $scene->image_prompt);

        return implode("\n", array_filter([
            'Animate the attached picture into a short vertical 9:16 video clip (about 5 seconds).',
            'Keep the characters, their looks, the drawing style and the composition exactly as in the picture.',
            $action !== '' ? "What happens: {$action}" : null,
            'Natural movement that fits the moment (hair, cloth, aura, eyes, small gestures) and a slow camera push-in.',
            'No text, captions or speech bubbles. No sound needed.',
        ]));
    }

    public function handle(): void
    {
        $scene = $this->scene->fresh();
        if ($scene === null || $scene->clip_status !== 'pending') {
            return;
        }
        /** @var ClipGenerator $generator */
        $generator = app('film.clip');

        try {
            if (! MuseTask::isCode($scene->clip_request_id)) {
                if (! $scene->image_path) {
                    $this->fail(new RuntimeException('Cảnh chưa có ảnh để làm video.'));

                    return;
                }
                if ($message = Credits::shortfall($scene->topicShort->user, self::price(), 'làm video cảnh')) {
                    $this->fail(new RuntimeException($message));

                    return;
                }
                $scene->update(['clip_request_id' => $generator->start($scene->image_path, self::prompt($scene))]);
                $this->release(30);

                return;
            }

            $clip = $generator->poll($scene->clip_request_id);
            if ($clip === null) {
                $this->release(self::POLL_SECONDS);

                return;
            }
        } catch (ImageGeneratorException $e) {
            if ($e->busy) {
                if (Cache::increment("muse-busy:topic-scene-clip:{$scene->id}") > GenerateTopicSceneImageJob::MAX_BUSY) {
                    $this->fail(new RuntimeException('Muse bận quá lâu (hơn 20 phút). Bấm tạo video lại sau.'));

                    return;
                }
                $this->release(30);

                return;
            }
            if ($e->rejected) {
                $this->fail(new RuntimeException('Muse từ chối làm video cảnh này. Thử ảnh khác cho cảnh rồi bấm lại. AI trả lời: '.mb_substr((string) $e->refusal, 0, 400)));

                return;
            }
            if ($e->retryable && Cache::increment("muse-retries:topic-scene-clip:{$scene->id}") <= 2) {
                $scene->update(['clip_request_id' => null]);
                $this->release(60);

                return;
            }
            $this->fail($e);

            return;
        }

        $code = $scene->clip_request_id;
        $path = "topic-shorts/{$scene->topic_short_id}/scene-{$scene->id}-clip-{$code}.{$clip['extension']}";
        Media::disk()->put($path, $clip['contents']);
        $old = $scene->clip_path;
        $scene->update(['clip_path' => $path, 'clip_status' => 'ready', 'clip_request_id' => null, 'clip_error' => null]);
        $old && $old !== $path && Media::delete($old);
        self::resetCounters($scene);

        Credits::charge(
            $scene->topicShort->user_id,
            self::price(),
            'clip',
            "topic-scene-clip:{$scene->id}:{$code}",
            "Short {$scene->topicShort->topic}: video cảnh {$scene->position}",
        );
    }

    public function failed(?Throwable $e): void
    {
        $scene = $this->scene->fresh();
        // Đã có video cũ thì vẫn dùng, chỉ kèm lời nhắn.
        $scene?->update(['clip_status' => $scene->clip_path ? 'ready' : 'failed', 'clip_request_id' => null, 'clip_error' => mb_substr($e?->getMessage() ?: 'Làm video thất bại.', 0, 600)]);
    }
}
