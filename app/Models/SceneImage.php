<?php

namespace App\Models;

use App\Enums\SceneImageStatus;
use App\Services\Image\MuseBrowserImageGenerator;
use Database\Factories\SceneImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

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

    /** Lời giải thích của muse.ai nếu ảnh này bị Muse từ chối (MuseBrowserImageGenerator::REJECTED), null nếu không. */
    public function museRejection(): ?string
    {
        if ($this->status !== SceneImageStatus::Failed || ! str_starts_with((string) $this->error, MuseBrowserImageGenerator::REJECTED)) {
            return null;
        }

        return trim(Str::after((string) $this->error, 'Muse trả lời:'));
    }
}
