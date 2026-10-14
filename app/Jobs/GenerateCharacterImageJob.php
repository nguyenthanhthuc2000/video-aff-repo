<?php

namespace App\Jobs;

use App\Models\SeriesCharacterImage;
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
use Throwable;

/** Muse vẽ một ảnh nhân vật (gửi rồi hỏi lại theo mã, không giữ hàng đợi). Ảnh chính đầu tiên tự thành ảnh chính của nhân vật. */
class GenerateCharacterImageJob implements ShouldQueue
{
    use Queueable;

    public const POLL_SECONDS = 10;

    public int $tries = 0;

    public int $timeout = 600;

    public function __construct(public SeriesCharacterImage $image) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(45);
    }

    public function handle(): void
    {
        $image = $this->image->fresh();
        if ($image === null || $image->status !== 'pending') {
            return;
        }
        $character = $image->character;
        $series = $character->series;
        /** @var AsyncImageGenerator $generator */
        $generator = app('film.character_image');
        $references = $this->references();

        try {
            if (! MuseTask::isCode($image->request_id)) {
                if ($message = Credits::shortfall($series->user, CreditPricing::settings()['steps']['image'], 'vẽ nhân vật')) {
                    $this->fail(new \RuntimeException($message));

                    return;
                }
                $image->update(['request_id' => $generator->start($references, (string) $image->prompt)]);
                $this->release(15);

                return;
            }

            $result = $generator->poll($image->request_id, $references);
            if ($result === null) {
                $this->release(self::POLL_SECONDS);

                return;
            }
        } catch (ImageGeneratorException $e) {
            if ($e->busy) {
                $this->release(20);

                return;
            }
            // Lỗi tạm thời: gửi lại yêu cầu mới (mã mới), tối đa 2 lần.
            if ($e->retryable && Cache::increment("muse-retries:character-image:{$image->id}") <= 2) {
                $image->update(['request_id' => null]);
                $this->release(30);

                return;
            }
            $this->fail($e);

            return;
        }

        $this->save($image, $result);
    }

    /** @return list<string> Ảnh chính của nhân vật (nếu có) để vẽ giống; ảnh chính mới thì vẽ từ mô tả. */
    private function references(): array
    {
        $character = $this->image->character;

        return $this->image->kind !== 'main' && $character->main_image_path ? [$character->main_image_path] : [];
    }

    private function save(SeriesCharacterImage $image, GeneratedImage $result): void
    {
        $character = $image->character;
        $path = "series/{$character->series_id}/characters/{$character->id}-{$image->id}.{$result->extension}";
        Media::disk()->put($path, $result->contents);
        $image->update(['status' => 'ready', 'path' => $path, 'error' => null]);

        if ($character->main_image_path === null && $image->kind === 'main') {
            $character->update(['main_image_path' => $path]);
        }

        Credits::charge(
            $character->series->user_id,
            CreditPricing::settings()['steps']['image'],
            'image',
            "character-image:{$image->id}",
            "Phim {$character->series->title}: ảnh nhân vật {$character->name}",
        );
    }

    public function failed(?Throwable $e): void
    {
        $this->image->fresh()?->update([
            'status' => 'failed',
            'error' => mb_substr($e?->getMessage() ?: 'Vẽ ảnh thất bại.', 0, 500),
        ]);
    }
}
