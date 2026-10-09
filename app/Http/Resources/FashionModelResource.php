<?php

namespace App\Http\Resources;

use App\Models\FashionModel;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FashionModel */
class FashionModelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'photo_url' => Media::url($this->photo_path),
            'has_consent' => $this->hasConsent(),
            'consent_at' => $this->consent_at,
        ];
    }
}
