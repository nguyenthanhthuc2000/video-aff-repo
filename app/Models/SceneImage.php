<?php

namespace App\Models;

use App\Enums\SceneImageStatus;
use Database\Factories\SceneImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SceneImage extends Model
{
    /** @use HasFactory<SceneImageFactory> */
    use HasFactory;

    protected $fillable = [
        'status',
        'path',
        'provider',
        'provider_request_id',
        'cost_usd',
        'error',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'status' => SceneImageStatus::class,
            'cost_usd' => 'decimal:4',
        ];
    }

    public function scene(): BelongsTo
    {
        return $this->belongsTo(Scene::class);
    }
}
