<?php

namespace App\Jobs;

use App\Models\TopicShortScene;
use App\Services\Image\GeneratedImage;
use App\Services\Image\ImageGeneratorException;
use App\Support\AiProviders;
use App\Support\CreditPricing;
use App\Support\Credits;
use App\Support\Media;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/** Muse (hoặc nhà cung cấp ảnh đã chọn cho khung hình) vẽ ảnh cảnh theo prompt, khung dọc 9:16. */
class GenerateTopicSceneImageJob implements ShouldQueue
{
    use Queueable;

    public const POLL_SECONDS = 10;

    public int $tries = 0;

    public int $timeout = 600;

    public function __construct(public TopicShortScene $scene) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(3);
    }

    public static function prompt(TopicShortScene $scene): string
    {
        return "FRAME: vertical 9:16 portrait.\nSTYLE: 2D Japanese anime, dramatic cel shading, high detail.\n\n".trim((string) $scene->image_prompt);
    }

    public function handle(): void
    {
        $scene = $this->scene->fresh();
        if ($scene === null || $scene->image_status !== 'pending') {
            return;
        }
        $generator = AiProviders::imageGenerator('keyframe');

        try {
            if ($scene->request_id === null) {
                if ($message = Credits::shortfall($scene->topicShort->user, CreditPricing::settings()['steps']['image'], 'vẽ ảnh')) {
                    $this->fail(new RuntimeException($message));

                    return;
                }
                $scene->update(['request_id' => $generator->start([], self::prompt($scene))]);
                $this->release(15);

                return;
            }

            $result = $generator->poll($scene->request_id, []);
            if ($result === null) {
                $this->release(self::POLL_SECONDS);

                return;
            }
        } catch (ImageGeneratorException $e) {
            if ($e->busy) {
                $this->release(30);

                return;
            }
            if ($e->retryable && Cache::increment("muse-retries:topic-scene:{$scene->id}") <= 2) {
                $scene->update(['request_id' => null]);
                $this->release(30);

                return;
            }
            $this->fail($e);

            return;
        }

        $this->save($scene, $result);
    }

    private function save(TopicShortScene $scene, GeneratedImage $result): void
    {
        $path = "topic-shorts/{$scene->topic_short_id}/scene-{$scene->id}-muse-".substr(md5($result->contents), 0, 8).".{$result->extension}";
        Media::disk()->put($path, $result->contents);
        $old = $scene->image_path;
        $scene->update(['image_source' => 'muse', 'image_status' => 'ready', 'image_path' => $path, 'image_url' => null, 'image_error' => null, 'request_id' => null]);
        $old && $old !== $path && Media::delete($old);

        Credits::charge(
            $scene->topicShort->user_id,
            CreditPricing::settings()['steps']['image'],
            'image',
            "topic-scene-image:{$scene->id}:".substr(md5($path), 0, 8),
            "Short {$scene->topicShort->topic}: ảnh cảnh {$scene->position}",
        );
    }

    public function failed(?Throwable $e): void
    {
        $this->scene->fresh()?->update(['image_status' => 'failed', 'request_id' => null, 'image_error' => mb_substr($e?->getMessage() ?: 'Vẽ ảnh thất bại.', 0, 500)]);
    }
}
