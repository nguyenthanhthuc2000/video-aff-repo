<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Một lần Muse phân tích (hiện có: lên phân cảnh từ video mẫu + nhạc), lưu nguyên để làm dữ liệu xây bộ skill viết prompt. */
class MuseAnalysis extends Model
{
    protected $fillable = [
        'user_id', 'video_project_id', 'kind', 'request_code', 'status', 'context', 'prompt', 'reply', 'result', 'error', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'result' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function videoProject(): BelongsTo
    {
        return $this->belongsTo(VideoProject::class);
    }

    /** Đầu vào của lần phân tích: sản phẩm, kiểu video, video mẫu, nhạc (để biết phân tích này ứng với trường hợp nào). */
    public static function contextFor(VideoProject $project, array $extra = []): array
    {
        $settings = $project->videoSettings();
        $sample = $project->sampleVideo;
        $track = $project->audioTrack;

        return [
            'product' => $project->product?->name,
            'category' => ProductCategory::detect($project->product)?->name,
            'voice_mode' => $project->voice_mode?->value,
            'one_take' => (bool) $project->one_take,
            'motion_styles' => $project->motion_styles,
            'seconds' => $settings['seconds'],
            'scenes' => $settings['scenes'],
            'sample_video' => $sample ? ['id' => $sample->id, 'name' => $sample->name, 'path' => $sample->path, 'duration_ms' => $sample->duration_ms, 'has_audio' => (bool) $sample->has_audio] : null,
            'audio_track' => $track ? ['id' => $track->id, 'name' => $track->name, 'path' => $track->path, 'duration_ms' => $track->duration_ms, 'lyrics' => filled($track->lyrics)] : null,
            ...$extra,
        ];
    }
}
