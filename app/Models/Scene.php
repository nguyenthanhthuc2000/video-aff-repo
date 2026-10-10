<?php

namespace App\Models;

use App\Enums\SceneClipStatus;
use App\Enums\SceneImageStatus;
use App\Enums\ScenePhase;
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
        'phase',
        'background_id',
        'duration_ms',
        'visual',
        'on_screen_text',
        'voiceover',
        'image_prompt',
        'clip_path',
        'clip_status',
        'clip_error',
        'clip_request_id',
        'clip_prompt',
        'clip_started_at',
        'clip_completed_at',
        'sample_video_id',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'duration_ms' => 'integer',
            'clip_status' => SceneClipStatus::class,
            'clip_started_at' => 'datetime',
            'clip_completed_at' => 'datetime',
            'phase' => ScenePhase::class,
        ];
    }

    /** Bối cảnh người dùng cung cấp cho cảnh (null = AI tự nghĩ bối cảnh theo prompt). */
    public function background(): BelongsTo
    {
        return $this->belongsTo(Background::class);
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

    /** Video mẫu riêng của cảnh (null = theo dự án). */
    public function sampleVideo(): BelongsTo
    {
        return $this->belongsTo(SampleVideo::class);
    }

    /** Video mẫu dùng để sao chép chuyển động cho cảnh: của cảnh, không có thì của dự án. */
    public function motionReference(): ?SampleVideo
    {
        return $this->sampleVideo ?? $this->videoProject->sampleVideo;
    }
}
