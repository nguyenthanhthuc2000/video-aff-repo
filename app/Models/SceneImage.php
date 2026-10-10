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
        'prompt',
        'completed_at',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'status' => SceneImageStatus::class,
            'completed_at' => 'datetime',
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
        $error = (string) $this->error;
        $rejected = str_starts_with($error, MuseBrowserImageGenerator::REJECTED) || str_starts_with($error, MuseBrowserImageGenerator::REJECTED_LEGACY);
        if ($this->status !== SceneImageStatus::Failed || ! $rejected) {
            return null;
        }

        return trim(str_contains($error, 'AI trả lời:') ? Str::after($error, 'AI trả lời:') : Str::after($error, 'Muse trả lời:'));
    }
}
