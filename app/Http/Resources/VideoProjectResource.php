<?php

namespace App\Http\Resources;

use App\Enums\SceneImageStatus;
use App\Models\VideoProject;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin VideoProject */
class VideoProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'is_busy' => $this->status->isBusy(),
            'error' => $this->error,
            'script' => $this->script,
            'selected_hook' => $this->selected_hook,
            'has_music' => $this->music !== null,
            'image_cost_usd' => $this->when(isset($this->image_cost_usd), fn () => round((float) $this->image_cost_usd, 4)),
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'primary_image_url' => Media::url($this->product->primaryImage?->path),
            ]),
            'fashion_model' => $this->whenLoaded('fashionModel', fn () => [
                'id' => $this->fashionModel->id,
                'name' => $this->fashionModel->name,
                'photo_url' => Media::url($this->fashionModel->photo_path),
            ]),
            'scenes' => $this->whenLoaded('scenes', fn () => $this->scenes->map(fn ($scene) => [
                'id' => $scene->id,
                'position' => $scene->position,
                'duration_ms' => $scene->duration_ms,
                'visual' => $scene->visual,
                'on_screen_text' => $scene->on_screen_text,
                'voiceover' => $scene->voiceover,
                'image_prompt' => $scene->image_prompt,
                'image' => $scene->relationLoaded('latestImage') && $scene->latestImage ? [
                    'id' => $scene->latestImage->id,
                    'status' => $scene->latestImage->status->value,
                    'url' => Media::url($scene->latestImage->path),
                    'error' => $scene->latestImage->error,
                ] : null,
            ])),
            'all_approved' => $this->whenLoaded('scenes', fn () => $this->scenes->isNotEmpty()
                && $this->scenes->every(fn ($scene) => $scene->latestImage?->status === SceneImageStatus::Approved)),
            'renders' => $this->whenLoaded('renders', fn () => $this->renders->map(fn ($render) => [
                'id' => $render->id,
                'hook' => $render->hook,
                'status' => $render->status->value,
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
}
