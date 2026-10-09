<?php

namespace App\Http\Resources;

use App\Models\Product;
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
            'price' => $this->price,
            'details' => (object) ($this->details ?? []),
            'reviews' => $this->reviews,
            'primary_image_url' => Media::url($this->primaryImage?->path),
            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($image) => [
                'id' => $image->id,
                'url' => Media::url($image->path),
                'is_primary' => $image->is_primary,
            ])),
            'created_at' => $this->created_at,
        ];
    }
}
