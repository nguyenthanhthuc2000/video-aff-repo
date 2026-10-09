<?php

namespace App\Jobs;

use App\Enums\SceneImageStatus;
use App\Enums\VideoProjectStatus;
use App\Models\SceneImage;
use App\Services\Image\AsyncImageGenerator;
use App\Services\Image\GeneratedImage;
use App\Services\Image\ImageGenerator;
use App\Services\Image\ImageGeneratorException;
use App\Services\Image\MuseTask;
use App\Services\Image\Prompt\RuleScenePromptRewriter;
use App\Services\Image\Prompt\ScenePromptRewriter;
use App\Support\Media;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateSceneImageJob implements ShouldQueue
{
    use Queueable;

    /**
     * Sản phẩm thời trang (ren, đồ ôm...) dễ bị Muse từ chối nếu ảnh trông gợi cảm:
     * yêu cầu ảnh trang nhã và để Muse tự chỉnh phần chưa phù hợp thay vì từ chối.
     */
    public const MODEST_IMAGE = 'Keep it modest and tasteful, like an everyday commercial fashion catalog: not sexy, not provocative, '
        .'no emphasis on body parts, natural relaxed pose, eye-level camera. '
        .'If any part of this request is not appropriate to create, adjust it yourself (pose, framing, camera angle, setting, '
        .'or show the garment on a mannequin or hanger for close-ups) and create that modest version instead of declining.';

    /** Hỏi lại kết quả muse.ai mỗi bấy nhiêu giây. */
    public const POLL_SECONDS = 10;

    // Số lần thử cho driver đồng bộ (fal); muse.ai (gửi rồi hỏi lại) dùng retryUntil.
    public int $tries = 3;

    public int $timeout = 600;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /**
     * @param  ?string  $rewriteReason  Viết prompt mới trước khi tạo: lời từ chối của Muse cho lần trước,
     *                                  hoặc RewritePrompt::VARIATION khi người dùng bấm Tạo lại để có ảnh khác.
     */
    public function __construct(public SceneImage $image, public ?string $rewriteReason = null) {}

    /** Mỗi lần hỏi lại là một lần chạy job: giới hạn theo thời gian thay vì số lần. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHour();
    }

    public function handle(ImageGenerator $generator, ScenePromptRewriter $rewriter): void
    {
        // Đã có kết quả hoặc đã bị thay bằng lần tạo khác.
        if ($this->image->fresh()?->status !== SceneImageStatus::Pending) {
            return;
        }

        // Chỉ viết lại một lần cho mỗi ảnh (job được chạy lại nhiều lần khi hỏi kết quả).
        if ($this->rewriteReason !== null && Cache::add("prompt-rewritten:{$this->image->id}", true, now()->addDay())) {
            $this->rewritePrompt($rewriter);
        }

        $references = $this->references();

        if ($generator instanceof AsyncImageGenerator) {
            $result = $this->runAsync($generator, $references);
        } else {
            $result = $this->runSync($generator, $references);
        }

        if ($result !== null) {
            $this->save($result);
        }
    }

    /** Claude viết prompt mới cho cảnh (trang nhã, khác prompt cũ); Claude lỗi thì lọc bỏ câu gợi cảm. */
    private function rewritePrompt(ScenePromptRewriter $rewriter): void
    {
        $scene = $this->image->scene;
        try {
            $prompt = $rewriter->rewrite($scene, (string) $this->rewriteReason);
        } catch (Throwable $e) {
            Log::warning('scene_prompt_rewrite.failed', ['scene_id' => $scene->id, 'error' => $e->getMessage()]);
            $prompt = (new RuleScenePromptRewriter)->rewrite($scene, (string) $this->rewriteReason);
        }

        Log::info('scene_prompt_rewrite', ['scene_id' => $scene->id, 'old' => $scene->image_prompt, 'new' => $prompt]);
        $scene->update(['image_prompt' => $prompt]);
    }

    /** @return list<string> Ảnh người mẫu đứng đầu, sau đó là ảnh sản phẩm (ảnh chính trước). */
    private function references(): array
    {
        $project = $this->image->scene->videoProject;

        return [
            $project->fashionModel->photo_path,
            ...$project->product->images()
                ->orderByDesc('is_primary')
                ->orderBy('id')
                ->limit(config('videoaff.image_product_refs'))
                ->pluck('path')
                ->all(),
        ];
    }

    /** @param  list<string>  $references */
    private function runSync(ImageGenerator $generator, array $references): ?GeneratedImage
    {
        try {
            return $generator->generate($references, self::prompt($this->image->scene->image_prompt));
        } catch (ImageGeneratorException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 120);
            } else {
                $this->fail($e->getMessage());
            }

            return null;
        }
    }

    /**
     * Gửi yêu cầu (lưu mã vào provider_request_id) rồi nhả hàng đợi; các lần sau hỏi kết quả theo mã.
     * Nhờ vậy một queue worker xử lý được nhiều ảnh, nhiều sản phẩm cùng lúc.
     *
     * @param  list<string>  $references
     */
    private function runAsync(AsyncImageGenerator $generator, array $references): ?GeneratedImage
    {
        $image = $this->image;

        try {
            if (! MuseTask::isCode($image->provider_request_id)) {
                $image->update(['provider_request_id' => $generator->start($references, self::prompt($image->scene->image_prompt))]);
                $this->release(15);

                return null;
            }

            $result = $generator->poll($image->provider_request_id, $references);
            if ($result === null) {
                $this->release(self::POLL_SECONDS);
            }

            return $result;
        } catch (ImageGeneratorException $e) {
            // Lỗi tạm thời: gửi lại yêu cầu mới (mã mới), tối đa 2 lần.
            if ($e->retryable && Cache::increment("muse-retries:image:{$image->id}") <= 2) {
                $image->update(['provider_request_id' => null]);
                $this->release(30);
            } else {
                $this->fail($e->getMessage());
            }

            return null;
        }
    }

    private function save(GeneratedImage $result): void
    {
        $image = $this->image;
        $scene = $image->scene;
        $project = $scene->videoProject;

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
        $project->advanceAutopilot();
    }

    public function failed(?Throwable $e): void
    {
        $this->image->update([
            'status' => SceneImageStatus::Failed,
            'error' => $e?->getMessage() ?? 'Tạo ảnh thất bại.',
        ]);

        $project = $this->image->scene->videoProject;
        $project->syncImagingStatus();
        // Ảnh lỗi khi chạy ngầm: dừng lại cho người dùng xem, không tạo lại vô hạn.
        if ($project->autopilot && $project->status === VideoProjectStatus::Reviewing) {
            $project->update(['autopilot' => false, 'error' => 'Có ảnh cảnh tạo lỗi, đã dừng chạy ngầm. Tạo lại ảnh lỗi rồi bấm chạy ngầm lần nữa.']);
        }
    }

    /** Prompt gửi Muse: prompt của cảnh + yêu cầu giữ đúng người mẫu và sản phẩm. */
    public static function prompt(string $scenePrompt): string
    {
        return trim($scenePrompt)."\n\n"
            .'The person in the first attached image wears the exact garment shown in the other attached images. '
            .'Keep the person\'s face and body the same. Keep the garment\'s color, pattern, fabric and cut identical. '
            .'Photorealistic vertical 9:16 photo, no text, no watermark. '
            .self::MODEST_IMAGE.' '
            // Chữ của video nằm ở phần dưới khung hình: giữ mặt ở phần trên để không bị che.
            .'Compose so the face is in the upper half of the frame with some headroom; keep the bottom quarter simple (floor, table, background).';
    }
}
