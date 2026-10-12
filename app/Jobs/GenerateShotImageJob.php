<?php

namespace App\Jobs;

use App\Models\Shot;
use App\Services\Film\ShotPrompt;
use App\Services\Image\AsyncImageGenerator;
use App\Services\Image\GeneratedImage;
use App\Services\Image\ImageGeneratorException;
use App\Services\Image\MuseTask;
use App\Support\CreditPricing;
use App\Support\Credits;
use App\Support\Media;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/** Muse vẽ khung hình của một shot theo ảnh chính các nhân vật (gửi rồi hỏi lại theo mã). */
class GenerateShotImageJob implements ShouldQueue
{
    use Queueable;

    public const POLL_SECONDS = 10;

    public int $tries = 0;

    public int $timeout = 600;

    public function __construct(public Shot $shot, public string $prompt) {}

    public function retryUntil(): DateTimeInterface
    {
        // Cả tập có nhiều shot chờ lượt Muse: cho thời gian dài.
        return now()->addHours(6);
    }

    public function handle(): void
    {
        $shot = $this->shot->fresh();
        if ($shot === null || $shot->image_status !== 'pending') {
            return;
        }
        /** @var AsyncImageGenerator $generator */
        $generator = app('film.image');
        $references = ShotPrompt::references($shot);

        try {
            if (! MuseTask::isCode($shot->request_id)) {
                if ($message = Credits::shortfall($shot->episode->series->user, CreditPricing::settings()['steps']['image'], 'vẽ khung hình')) {
                    $this->fail(new RuntimeException($message));

                    return;
                }
                $shot->update(['request_id' => $generator->start($references, $this->prompt)]);
                $this->release(15);

                return;
            }

            $result = $generator->poll($shot->request_id, $references);
            if ($result === null) {
                $this->release(self::POLL_SECONDS);

                return;
            }
        } catch (ImageGeneratorException $e) {
            if ($e->busy) {
                $this->release(30);

                return;
            }
            if ($e->retryable && Cache::increment("muse-retries:shot:{$shot->id}") <= 2) {
                $shot->update(['request_id' => null]);
                $this->release(30);

                return;
            }
            $this->fail($e);

            return;
        }

        $this->save($shot, $result);
    }

    private function save(Shot $shot, GeneratedImage $result): void
    {
        $episode = $shot->episode;
        $image = $shot->images()->create(['path' => '', 'prompt' => $this->prompt, 'provider' => $result->provider, 'request_id' => $shot->request_id, 'approved' => true]);
        $path = "series/{$episode->series_id}/episodes/{$episode->id}/shot-{$shot->id}-{$image->id}.{$result->extension}";
        Media::disk()->put($path, $result->contents);
        $image->update(['path' => $path]);
        $shot->images()->whereKeyNot($image->id)->update(['approved' => false]);
        $shot->update(['image_status' => 'ready', 'image_path' => $path, 'request_id' => null, 'error' => null]);

        Credits::charge(
            $episode->series->user_id,
            CreditPricing::settings()['steps']['image'],
            'image',
            "shot-image:{$image->id}",
            "Phim {$episode->series->title}: khung hình tập {$episode->number}",
        );
        $episode->syncKeyframeStatus();
    }

    public function failed(?Throwable $e): void
    {
        $shot = $this->shot->fresh();
        $shot?->update(['image_status' => 'failed', 'request_id' => null, 'error' => mb_substr($e?->getMessage() ?: 'Vẽ khung hình thất bại.', 0, 500)]);
        $shot?->episode->syncKeyframeStatus();
    }
}
