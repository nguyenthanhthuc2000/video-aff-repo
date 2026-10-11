<?php

namespace App\Jobs;

use App\Enums\SceneImageStatus;
use App\Enums\ScenePhase;
use App\Enums\VideoProjectStatus;
use App\Models\Scene;
use App\Models\SceneImage;
use App\Services\Claude\AiUsage;
use App\Services\Image\AsyncImageGenerator;
use App\Services\Image\GeneratedImage;
use App\Services\Image\ImageGenerator;
use App\Services\Image\ImageGeneratorException;
use App\Services\Image\MuseTask;
use App\Services\Image\Prompt\RewritePrompt;
use App\Services\Image\Prompt\RuleScenePromptRewriter;
use App\Services\Image\Prompt\ScenePromptRewriter;
use App\Support\CreditPricing;
use App\Support\Credits;
use App\Support\Framing;
use App\Support\Media;
use App\Support\ProductCoverage;
use App\Support\ProductFidelity;
use App\Support\Realism;
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
    public const MODEST_IMAGE = 'Keep it modest and tasteful, like a real customer\'s everyday outfit photo: not sexy, not provocative, '
        .'no emphasis on body parts, natural relaxed pose, eye-level camera. '
        .'If any part of this request is not appropriate to create, adjust it yourself (pose, camera angle, setting) '
        .'and create that modest version instead of declining; '
        .'never alter the product itself to do so.';

    /**
     * Chỉ đúng một sản phẩm đang bán: Muse hay tự thêm túi, ví, phụ kiện cho "đủ bộ" (ví dụ sản phẩm là mũ mà ảnh có túi).
     * Sản phẩm luôn đeo / cầm tự nhiên trên người mẫu, không đặt rời hay lơ lửng.
     */
    public const ONLY_PRODUCT = 'Show only this one product: do not add any other bags, wallets, hats, glasses, jewelry, phones or accessories that are not in the reference photos. '
        .'The product is worn or held naturally by the person, never floating in the air or placed separately.';

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

        // Chưa gửi yêu cầu: kiểm tra số dư trước (trừ khi ảnh tạo xong).
        if (! MuseTask::isCode($this->image->provider_request_id)
            && ($message = Credits::shortfall($this->image->scene->videoProject->user, CreditPricing::settings()['steps']['image'], 'tạo ảnh cảnh'))) {
            $this->fail($message);

            return;
        }

        // Chỉ viết lại một lần cho mỗi ảnh (job được chạy lại nhiều lần khi hỏi kết quả).
        if ($this->rewriteReason !== null && Cache::add("prompt-rewritten:{$this->image->id}", true, now()->addDay())) {
            $this->rewritePrompt($rewriter, (string) $this->rewriteReason);
        }

        $references = $this->references();

        if ($generator instanceof AsyncImageGenerator) {
            $result = $this->runAsync($generator, $references, $rewriter);
        } else {
            $result = $this->runSync($generator, $references);
        }

        if ($result !== null) {
            $this->save($result);
        }
    }

    /** Claude viết prompt mới cho cảnh (trang nhã, khác prompt cũ); Claude lỗi thì lọc bỏ câu gợi cảm. */
    private function rewritePrompt(ScenePromptRewriter $rewriter, string $reason): void
    {
        $scene = $this->image->scene;
        try {
            $prompt = AiUsage::withContext(
                ['user_id' => $scene->videoProject?->user_id, 'video_project_id' => $scene->video_project_id, 'subject' => "scene:{$scene->id}"],
                fn () => $rewriter->rewrite($scene, $reason),
            );
        } catch (Throwable $e) {
            Log::warning('scene_prompt_rewrite.failed', ['scene_id' => $scene->id, 'error' => $e->getMessage()]);
            $prompt = (new RuleScenePromptRewriter)->rewrite($scene, $reason);
        }

        Log::info('scene_prompt_rewrite', ['scene_id' => $scene->id, 'old' => $scene->image_prompt, 'new' => $prompt]);
        $scene->update(['image_prompt' => $prompt]);
    }

    /**
     * @return list<string> Ảnh người mẫu đứng đầu, sau đó là ảnh sản phẩm (ảnh chính trước).
     *                      Cảnh "trước" của video biến hình: chỉ ảnh "trước", không có sản phẩm.
     */
    private function references(): array
    {
        $scene = $this->image->scene;
        $project = $scene->videoProject;

        // Bối cảnh người dùng cung cấp: ảnh nền đứng thứ hai (sau ảnh người mẫu).
        $background = $scene->background?->photo_path;

        if ($scene->phase === ScenePhase::Before && $project->beforeFashionModel) {
            return array_values(array_filter([$project->beforeFashionModel->photo_path, $background]));
        }

        // Đã chọn đúng ảnh màu / mẫu của bộ đồ: chỉ gửi ảnh đó để AI không lẫn sang màu khác.
        if ($scene->productImage) {
            return [$project->fashionModel->photo_path, ...array_filter([$background]), $scene->productImage->path];
        }

        return [
            $project->fashionModel->photo_path,
            ...array_filter([$background]),
            ...$scene->outfitProduct()->images()
                ->orderByDesc('is_primary')
                ->orderBy('id')
                // Muse nhận tối đa 4 ảnh: có ảnh nền thì bớt một ảnh sản phẩm.
                ->limit(min(config('videoaff.image_product_refs'), $background ? 2 : 3))
                ->pluck('path')
                ->all(),
        ];
    }

    /** @param  list<string>  $references */
    private function runSync(ImageGenerator $generator, array $references): ?GeneratedImage
    {
        try {
            $prompt = self::scenePrompt($this->image->scene);
            $this->image->update(['prompt' => $prompt]);

            return $generator->generate($references, $prompt);
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
    private function runAsync(AsyncImageGenerator $generator, array $references, ScenePromptRewriter $rewriter): ?GeneratedImage
    {
        $image = $this->image;

        try {
            if (! MuseTask::isCode($image->provider_request_id)) {
                $prompt = self::scenePrompt($image->scene);
                $image->update(['provider_request_id' => $generator->start($references, $prompt), 'prompt' => $prompt]);
                $this->release(15);

                return null;
            }

            $result = $generator->poll($image->provider_request_id, $references);
            if ($result === null) {
                $this->release(self::POLL_SECONDS);
            }

            return $result;
        } catch (ImageGeneratorException $e) {
            // Muse từ chối: tự viết lại prompt theo lời từ chối và gửi lại một lần (cuộc trò chuyện mới) trước khi báo người dùng.
            if ($e->rejected && Cache::add("muse-auto-rewrite:image:{$image->id}", true, now()->addDay())) {
                Log::info('scene_image.auto_rewrite', ['image_id' => $image->id, 'code' => $image->provider_request_id]);
                $this->rewritePrompt($rewriter, $e->refusal ?: RewritePrompt::VARIATION);
                $image->update(['provider_request_id' => null]);
                $this->release(5);

                return null;
            }
            // Đang đủ yêu cầu song song: chờ lượt rồi gửi (job giới hạn theo thời gian, retryUntil).
            if ($e->busy) {
                $this->release(20);

                return null;
            }
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
            'completed_at' => now(),
        ]);

        Credits::charge($project->user_id, CreditPricing::settings()['steps']['image'], 'image', "image:{$image->id}", "Ảnh cảnh {$scene->position}", $project->id);

        $project->syncImagingStatus();
        $project->advanceAutopilot();
    }

    public function failed(?Throwable $e): void
    {
        $this->image->update([
            'status' => SceneImageStatus::Failed,
            'error' => $e?->getMessage() ?? 'Tạo ảnh thất bại.',
            'completed_at' => now(),
        ]);

        $project = $this->image->scene->videoProject;
        $project->syncImagingStatus();
        // Ảnh lỗi khi chạy ngầm: dừng lại cho người dùng xem, không tạo lại vô hạn.
        if ($project->autopilot && $project->status === VideoProjectStatus::Reviewing) {
            $project->update(['autopilot' => false, 'error' => 'Có ảnh cảnh tạo lỗi, đã dừng chạy ngầm. Tạo lại ảnh lỗi rồi bấm chạy ngầm lần nữa.']);
        }
    }

    /** Prompt gửi Muse: prompt của cảnh + yêu cầu giữ đúng người mẫu và sản phẩm. */
    /** Prompt đầy đủ của cảnh (theo pha, loại sản phẩm, có ảnh nền bối cảnh hay không). */
    public static function scenePrompt(Scene $scene): string
    {
        return self::prompt($scene->image_prompt, $scene->phase, $scene->productLine(), $scene->background_id !== null, $scene->angle);
    }

    /** Ảnh nền bối cảnh của người dùng: đặt người vào đúng nơi đó, không lấy người / đồ trong ảnh nền. */
    public const BACKGROUND = 'Place the person inside exactly the location shown in the background photo: keep its layout, furniture, wall and floor colors and lighting, '
        .'as if photographed there; if the description above mentions a different place, ignore it and use the background photo. '
        .'Do not copy any people, clothing, bags or products that may appear in the background photo.';

    public static function prompt(string $scenePrompt, ?ScenePhase $phase = null, ?string $productLine = null, bool $background = false, ?string $angle = null): string
    {
        // Cảnh "trước" của video biến hình: giữ nguyên vẻ ngoài cũ trong ảnh "trước", không mặc sản phẩm.
        if ($phase === ScenePhase::Before) {
            return Framing::strip($scenePrompt)."\n\n"
                .($background ? 'The first attached image is the person; the second attached image is the background location. '.self::BACKGROUND.' ' : '')
                .'Keep the person in the attached image exactly as they look there: same face, same hairstyle and natural bare skin, no makeup. '
                .'The clothes must stay EXACTLY the same as in the attached photo (same garments, colors, patterns, fit and condition); '
                .'do not change, replace, add or remove any clothing or accessory, and ignore any clothing described above. '
                .'Vertical 9:16 candid photo, no text, no watermark. '
                .Framing::IMAGE.' '
                .Realism::IMAGE.' '
                .self::MODEST_IMAGE.' '
                .'Compose so the face is in the upper half of the frame with some headroom; keep the bottom quarter simple (floor, table, background).';
        }

        $angleLine = ProductCoverage::imageLine($angle);

        return Framing::strip($scenePrompt)."\n\n"
            .($angleLine !== '' ? $angleLine.' ' : '')
            .($productLine ?? 'The person is wearing or holding the exact product shown in the other attached images.').' '
            .($background
                ? 'The first attached image is the person; the second attached image is the background location; the other attached images show only the product. '.self::BACKGROUND.' '
                : 'The first attached image is the person; the other attached images show only the product. ')
            .'Keep the person\'s face and body the same. '
            .ProductFidelity::IMAGE.' '
            .self::ONLY_PRODUCT.' '
            .'Vertical 9:16 photo, no text, no watermark. '
            .Framing::IMAGE.' '
            .Realism::IMAGE.' '
            .self::MODEST_IMAGE.' '
            // Chữ của video nằm ở phần dưới khung hình: giữ mặt ở phần trên để không bị che.
            .'Compose so the face is in the upper half of the frame with some headroom; keep the bottom quarter simple (floor, table, background).';
    }
}
