<?php

namespace App\Models;

use App\Enums\SceneClipStatus;
use App\Enums\SceneImageStatus;
use App\Jobs\GenerateSceneClipJob;
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
        'clip_path',
        'clip_status',
        'clip_error',
        'clip_request_id',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'duration_ms' => 'integer',
            'clip_status' => SceneClipStatus::class,
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

    /** Đưa việc tạo clip chuyển động (muse.ai) vào hàng đợi. */
    public function queueClip(): void
    {
        $this->update(['clip_status' => SceneClipStatus::Pending, 'clip_error' => null, 'clip_request_id' => null]);
        GenerateSceneClipJob::dispatch($this);
    }
}
