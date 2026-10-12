<?php

namespace App\Http\Resources;

use App\Enums\ProductImportStatus;
use App\Models\Product;
use App\Services\ProductImport\PageSnapshot;
use App\Services\ProductImport\ProductExtractPrompt;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'shopee_url' => $this->shopee_url,
            'shop_name' => $this->shopee_url ? Product::shopName($this->shopee_url) : null,
            'price' => $this->price,
            'details' => (object) ($this->details ?? []),
            'reviews' => $this->reviews,
            'import_status' => $this->import_status?->value,
            'import_error' => $this->import_error,
            // Chế độ Claude bản web: prompt để copy sang claude.ai.
            'import_prompt' => $this->when(
                $this->import_status === ProductImportStatus::AwaitingReply && $this->import_snapshot !== null,
                fn () => ProductExtractPrompt::forWeb(new PageSnapshot(
                    $this->import_snapshot['url'] ?? '',
                    $this->import_snapshot['title'] ?? '',
                    $this->import_snapshot['text'] ?? '',
                )),
            ),
            'claude_web_url' => config('videoaff.claude_web_url'),
            'images_approved' => $this->images_approved_at !== null,
            'primary_image_url' => Media::url($this->primaryImage?->path),
            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($image) => [
                'id' => $image->id,
                'url' => Media::url($image->path),
                'is_primary' => $image->is_primary,
            ])),
            'images_count' => $this->whenCounted('images'),
            'video_projects_count' => $this->whenCounted('videoProjects'),
            'created_at' => $this->created_at,
        ];
    }
}
