<?php

namespace App\Jobs;

use App\Enums\SceneImageStatus;
use App\Models\SceneImage;
use App\Services\Image\ImageGenerator;
use App\Services\Image\ImageGeneratorException;
use App\Support\Media;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateSceneImageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public SceneImage $image) {}

    public function handle(ImageGenerator $generator): void
    {
        $image = $this->image;
        $scene = $image->scene;
        $project = $scene->videoProject;

        $references = [
            $project->fashionModel->photo_path,
            ...$project->product->images()
                ->orderByDesc('is_primary')
                ->orderBy('id')
                ->limit(config('videoaff.image_product_refs'))
                ->pluck('path')
                ->all(),
        ];

        try {
            $result = $generator->generate($references, self::prompt($scene->image_prompt));
        } catch (ImageGeneratorException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 120);

                return;
            }

            $this->fail($e->getMessage());

            return;
        }

        $path = "scenes/{$project->id}/{$scene->id}-{$image->id}.{$result->extension}";
        Media::disk()->put($path, $result->contents);

        $image->update([
            'status' => SceneImageStatus::Ready,
            'path' => $path,
            'provider' => $result->provider,
            'provider_request_id' => $result->requestId,
            'cost_usd' => $result->costUsd,
            'error' => null,
        ]);

        $project->syncImagingStatus();
    }

    public function failed(?Throwable $e): void
    {
        $this->image->update([
            'status' => SceneImageStatus::Failed,
            'error' => $e?->getMessage() ?? 'Tạo ảnh thất bại.',
        ]);

        $this->image->scene->videoProject->syncImagingStatus();
    }

    /** Prompt gửi Muse: prompt của cảnh + yêu cầu giữ đúng người mẫu và sản phẩm. */
    public static function prompt(string $scenePrompt): string
    {
        return trim($scenePrompt)."\n\n"
            .'The person in the first attached image wears the exact garment shown in the other attached images. '
            .'Keep the person\'s face and body the same. Keep the garment\'s color, pattern, fabric and cut identical. '
            .'Photorealistic vertical 9:16 photo, no text, no watermark.';
    }
}
