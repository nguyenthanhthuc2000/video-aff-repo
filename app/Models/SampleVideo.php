<?php

namespace App\Models;

use Database\Factories\SampleVideoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Video mẫu (điệu nhảy, cách tạo dáng...) người dùng tải lên để sao chép chuyển động. */
class SampleVideo extends Model
{
    /** @use HasFactory<SampleVideoFactory> */
    use HasFactory;

    protected $fillable = [
        'name', 'path', 'thumbnail_path', 'reference_path', 'analysis_status', 'motion_analysis', 'analysis_error', 'has_audio', 'audio_track_id', 'mime', 'size_bytes', 'duration_ms', 'width', 'height',
        'source_url', 'notes', 'rights_confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'duration_ms' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'rights_confirmed_at' => 'datetime',
            'motion_analysis' => 'array',
            'has_audio' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Bài nhạc tách từ tiếng của video mẫu (thư viện Âm thanh). */
    public function audioTrack(): BelongsTo
    {
        return $this->belongsTo(AudioTrack::class);
    }

    /**
     * Dòng thời gian chuyển động đã phân tích, dạng chữ để đưa vào prompt Muse.
     */
    public function motionTimeline(): ?string
    {
        $analysis = $this->motion_analysis;
        if (! is_array($analysis) || empty($analysis['steps'])) {
            return null;
        }

        $steps = collect($analysis['steps'])
            ->map(fn (array $step) => sprintf('%.1f–%.1fs: %s', $step['start'] ?? 0, $step['end'] ?? 0, rtrim(trim((string) ($step['motion'] ?? '')), '.')))
            ->implode('; ');

        return "Motion timeline to reproduce exactly: {$steps}."
            .(! empty($analysis['tempo']) ? " Tempo: {$analysis['tempo']}." : '')
            .(! empty($analysis['framing']) ? " Camera: {$analysis['framing']}." : '');
    }
}
