<?php

namespace App\Models;

use App\Enums\SceneImageStatus;
use App\Enums\VideoProjectStatus;
use Database\Factories\VideoProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class VideoProject extends Model
{
    /** @use HasFactory<VideoProjectFactory> */
    use HasFactory;

    protected $fillable = [
        'product_id',
        'fashion_model_id',
        'status',
        'script',
        'selected_hook',
        'music',
        'error',
    ];

    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'status' => VideoProjectStatus::class,
            'script' => 'array',
            'selected_hook' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function fashionModel(): BelongsTo
    {
        return $this->belongsTo(FashionModel::class);
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(Scene::class)->orderBy('position');
    }

    public function sceneImages(): HasManyThrough
    {
        return $this->hasManyThrough(SceneImage::class, Scene::class);
    }

    public function renders(): HasMany
    {
        return $this->hasMany(Render::class)->latest('id');
    }

    /** Chuyển sang bước duyệt khi không còn ảnh cảnh nào đang tạo. */
    public function syncImagingStatus(): void
    {
        $this->refresh();

        if ($this->status !== VideoProjectStatus::Imaging) {
            return;
        }

        $pending = $this->scenes()
            ->whereHas('latestImage', fn ($query) => $query->where('status', SceneImageStatus::Pending))
            ->exists();

        if (! $pending) {
            $this->update(['status' => VideoProjectStatus::Reviewing]);
        }
    }

    /** Mọi cảnh đều có ảnh đã duyệt (điều kiện để xuất video). */
    public function allScenesApproved(): bool
    {
        return $this->scenes()->exists()
            && $this->scenes()->whereDoesntHave('approvedImage')->doesntExist();
    }

    public function markFailed(string $error): void
    {
        $this->update(['status' => VideoProjectStatus::Failed, 'error' => $error]);
    }
}
