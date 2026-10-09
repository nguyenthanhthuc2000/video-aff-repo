<?php

namespace App\Models;

use App\Enums\SceneImageStatus;
use Database\Factories\SceneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Scene extends Model
{
    /** @use HasFactory<SceneFactory> */
    use HasFactory;

    protected $fillable = [
        'position',
        'duration_ms',
        'visual',
        'on_screen_text',
        'voiceover',
        'image_prompt',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    public function videoProject(): BelongsTo
    {
        return $this->belongsTo(VideoProject::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(SceneImage::class)->latest('id');
    }

    /** Ảnh mới nhất của cảnh (mỗi lần tạo lại là một dòng mới). */
    public function latestImage(): HasOne
    {
        return $this->hasOne(SceneImage::class)->latestOfMany();
    }

    public function approvedImage(): HasOne
    {
        return $this->hasOne(SceneImage::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->where('status', SceneImageStatus::Approved),
        );
    }
}
