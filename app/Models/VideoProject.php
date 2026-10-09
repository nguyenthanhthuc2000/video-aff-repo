<?php

namespace App\Models;

use App\Enums\VideoProjectStatus;
use Database\Factories\VideoProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function renders(): HasMany
    {
        return $this->hasMany(Render::class)->latest('id');
    }

    public function markFailed(string $error): void
    {
        $this->update(['status' => VideoProjectStatus::Failed, 'error' => $error]);
    }
}
