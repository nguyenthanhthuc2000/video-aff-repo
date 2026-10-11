<?php

namespace App\Http\Resources;

use App\Enums\MotionStyle;
use App\Enums\SceneImageStatus;
use App\Enums\VoiceMode;
use App\Http\Controllers\SceneImageController;
use App\Http\Controllers\ScriptController;
use App\Jobs\GenerateSceneImageJob;
use App\Models\ProductCategory;
use App\Models\SampleVideo;
use App\Models\VideoProject;
use App\Services\Script\ScriptPrompt;
use App\Support\Media;
use App\Support\MotionCopy;
use App\Support\MusePlan;
use App\Support\ProductCoverage;
use App\Support\SceneSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin VideoProject */
class VideoProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Câu tả sản phẩm cho prompt đầy đủ của từng cảnh (tính một lần).
        // Prompt chỉ hiện cho admin, hoặc khi người dùng phải tự dán prompt (chế độ thủ công: tạo ảnh trên muse.ai, tự làm clip).
        $isAdmin = (bool) $request->user()?->isAdmin();
        $showImagePrompt = $isAdmin || SceneImageController::webMode();
        $showMotionPrompt = $isAdmin || ! VideoProject::clipsAvailable();

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            // Clip đang tạo cũng cần giao diện tự làm mới.
            'is_busy' => $this->status->isBusy() || $this->clipsPending(),
            'clips_available' => VideoProject::clipsAvailable(),
            'progress' => $this->progress(),
            'autopilot' => $this->autopilot,
            'autopilot_blocker' => $this->when($this->relationLoaded('scenes'), fn () => $this->autopilotBlocker()),
            'error' => $this->error,
            'script' => $this->script,
            'selected_hook' => $this->selected_hook,
            'outfits' => array_map(fn (array $item) => array_diff_key($item, ['product' => 0]), $this->outfitList()),
            'render_text' => $this->render_text ?? true,
            'has_music' => $this->music !== null,
            'audio_track' => $this->audioTrack ? ['id' => $this->audioTrack->id, 'name' => $this->audioTrack->name, 'url' => Media::url($this->audioTrack->path)] : null,
            'script_mode' => ScriptController::webMode() ? 'web' : 'api',
            'claude_web_url' => config('videoaff.claude_web_url'),
            'script_prompt' => $this->when(ScriptController::webMode() && $this->relationLoaded('scenes'), fn () => ScriptPrompt::forWeb($this->resource)),
            'motion_style' => $this->motion_style?->value,
            'motion_styles' => array_map(fn ($style) => $style->value, $this->motionStyles()),
            'has_motion' => $this->hasMotion(),
            'sample_video' => $this->sampleVideo ? self::sample($this->sampleVideo) : null,
            'background_ids' => $this->background_ids ?? [],
            'motion_style_label' => implode(', ', array_map(fn ($style) => $style->label(), $this->motionStyles())) ?: null,
            'motion_web_url' => config('videoaff.motion_web_url'),
            // Cài đặt video và bối cảnh gợi ý theo sản phẩm (trang dự án).
            'video_settings' => $this->whenLoaded('scenes', fn () => $this->videoSettings()),
            'scene_suggestions' => $this->whenLoaded('scenes', fn () => SceneSettings::suggestFor($this->product)),
            'motion_suggestions' => $this->whenLoaded('scenes', fn () => array_map(fn ($style) => $style->value, MotionStyle::suggestFor($this->product))),
            'product_category' => $this->whenLoaded('scenes', fn () => ProductCategory::detect($this->product)?->name),
            'image_mode' => SceneImageController::webMode() ? 'web' : 'api',
            'muse_web_url' => config('videoaff.muse_web_url'),
            'references' => $this->whenLoaded('scenes', fn () => [
                ['label' => 'Ảnh 1: người mẫu', 'url' => Media::url($this->fashionModel->photo_path)],
                ...$this->product->images()
                    ->orderByDesc('is_primary')->orderBy('id')
                    ->limit(config('videoaff.image_product_refs'))
                    ->get()
                    ->values()
                    ->map(fn ($image, $i) => ['label' => 'Ảnh '.($i + 2).': sản phẩm', 'url' => Media::url($image->path)])
                    ->all(),
            ]),
            // Mọi sản phẩm trong video (video gộp: theo thứ tự bộ đồ) kèm toàn bộ ảnh, ảnh AI dùng và các cảnh mặc.
            'video_products' => $this->whenLoaded('scenes', fn () => $this->videoProducts()),
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'primary_image_url' => Media::url($this->product->primaryImage?->path),
            ]),
            'video_type' => $this->video_type->value,
            'one_take' => (bool) $this->one_take,
            // Góc quay sản phẩm chọn được cho từng cảnh.
            'angle_options' => ProductCoverage::applies($this->resource) ? ProductCoverage::options($this->resource) : [],
            'voice_mode' => ($this->voice_mode ?? VoiceMode::Music)->value,
            'music_start_ms' => $this->music_start_ms,
            'muse_plan_status' => $this->muse_plan_status,
            'muse_plan_error' => $this->muse_plan_error,
            'muse_plan' => MusePlan::of($this->resource),
            'post_captions_status' => $this->post_captions_status,
            'post_captions' => $this->post_captions,
            'video_type_label' => $this->video_type->label(),
            'before_fashion_model' => $this->whenLoaded('beforeFashionModel', fn () => $this->beforeFashionModel ? [
                'id' => $this->beforeFashionModel->id,
                'name' => $this->beforeFashionModel->name,
                'photo_url' => Media::url($this->beforeFashionModel->photo_path),
            ] : null),
            'fashion_model' => $this->whenLoaded('fashionModel', fn () => [
                'id' => $this->fashionModel->id,
                'name' => $this->fashionModel->name,
                'photo_url' => Media::url($this->fashionModel->photo_path),
            ]),
            'scenes' => $this->whenLoaded('scenes', fn () => $this->scenes->map(fn ($scene) => [
                'id' => $scene->id,
                'position' => $scene->position,
                'phase' => $scene->phase?->value,
                'angle' => $scene->angle,
                'angle_label' => ProductCoverage::label($scene->angle),
                'duration_ms' => $scene->duration_ms,
                'visual' => $scene->visual,
                'on_screen_text' => $scene->on_screen_text,
                'voiceover' => $scene->voiceover,
                ...($isAdmin ? ['image_prompt' => $scene->image_prompt] : []),
                ...($showImagePrompt ? ['full_prompt' => GenerateSceneImageJob::scenePrompt($scene)] : []),
                // Có video mẫu (của cảnh hoặc dự án) thì sao chép chuyển động, không thì kiểu có sẵn.
                ...($showMotionPrompt ? ['motion_prompt' => $scene->motionReference() ? MotionCopy::prompt($scene) : $this->motionStyleFor($scene)?->prompt($scene)] : []),
                'sample_video_id' => $scene->sample_video_id,
                'background' => $scene->background?->present(),
                'outfit' => $scene->hasOutfit() || $this->outfits ? $scene->outfit() : null,
                'motion_reference' => ($reference = $scene->motionReference()) ? self::sample($reference) : null,
                'motion_style_label' => $this->motionStyleFor($scene)?->label(),
                'clip_url' => Media::url($scene->clip_path),
                'clip_status' => $scene->clip_status?->value,
                'clip_error' => $scene->clip_error,
                'image' => $scene->relationLoaded('latestImage') && $scene->latestImage ? [
                    'id' => $scene->latestImage->id,
                    'status' => $scene->latestImage->status->value,
                    'url' => Media::url($scene->latestImage->path),
                    'error' => $scene->latestImage->error,
                ] : null,
            ])),
            'all_approved' => $this->whenLoaded('scenes', fn () => $this->scenes->isNotEmpty()
                && $this->scenes->every(fn ($scene) => $scene->latestImage?->status === SceneImageStatus::Approved)),
            'latest_render' => $this->whenLoaded('latestDoneRender', fn () => $this->latestDoneRender ? [
                'id' => $this->latestDoneRender->id,
                'url' => Media::url($this->latestDoneRender->path),
                'download_url' => route('renders.download', $this->latestDoneRender),
                'duration_ms' => $this->latestDoneRender->duration_ms,
                'hook' => $this->script['hooks'][$this->latestDoneRender->hook] ?? null,
                'created_at' => $this->latestDoneRender->created_at,
            ] : null),
            'renders' => $this->whenLoaded('renders', fn () => $this->renders->map(fn ($render) => [
                'id' => $render->id,
                'hook' => $render->hook,
                'with_text' => $render->with_text,
                'status' => $render->status->value,
                'progress' => $render->progress,
                'url' => Media::url($render->path),
                'download_url' => $render->path ? route('renders.download', $render) : null,
                'duration_ms' => $render->duration_ms,
                'error' => $render->error,
                'created_at' => $render->created_at,
            ])),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /** @return array{id: int, name: string, url: ?string, thumbnail_url: ?string} */
    private static function sample(SampleVideo $video): array
    {
        return ['id' => $video->id, 'name' => $video->name, 'url' => Media::url($video->path), 'thumbnail_url' => Media::url($video->thumbnail_path)];
    }

    /** @return list<array<string, mixed>> */
    private function videoProducts(): array
    {
        $outfits = $this->outfitList() ?: [['product_id' => $this->product_id, 'product_image_id' => null, 'color' => null, 'product' => $this->product]];
        $limit = config('videoaff.image_product_refs');

        return collect($outfits)->groupBy('product_id', true)->map(function ($items) use ($limit) {
            $product = $items->first()['product'];
            $images = $product->images()->orderByDesc('is_primary')->orderBy('id')->get();
            $chosen = $items->pluck('product_image_id')->filter()->all();
            // Không chọn ảnh riêng thì AI xem vài ảnh đầu (ảnh chính trước).
            $usesAll = $items->contains(fn ($item) => $item['product_image_id'] === null);
            $scenes = $this->scenes
                ->filter(fn ($scene) => $scene->phase?->value !== 'before' && ($scene->product_id ?? $this->product_id) === $product->id)
                ->pluck('position')->values()->all();

            return [
                'id' => $product->id,
                'name' => $product->name,
                'price' => $product->price,
                'shopee_url' => $product->shopee_url,
                'url' => route('products.edit', $product),
                'outfit_numbers' => $items->keys()->map(fn ($key) => $key + 1)->values()->all(),
                'colors' => $items->pluck('color')->filter()->unique()->values()->all(),
                'scenes' => $scenes,
                'images' => $images->values()->map(fn ($image, $i) => [
                    'id' => $image->id,
                    'url' => Media::url($image->path),
                    'used' => in_array($image->id, $chosen, true) || ($usesAll && $i < $limit),
                ])->all(),
            ];
        })->values()->all();
    }
}
