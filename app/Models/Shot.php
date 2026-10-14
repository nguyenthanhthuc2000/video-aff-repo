<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Một shot của tập: bối cảnh, nhân vật, góc máy, hành động, lời thoại → keyframe → clip. */
class Shot extends Model
{
    protected $fillable = [
        'episode_id', 'sequence', 'position', 'series_location_id', 'character_ids', 'camera', 'camera_move', 'action', 'motion', 'sound_cue', 'animate', 'dialogue',
        'duration_ms', 'song_ms', 'lyric', 'label', 'lip_sync', 'continues_previous', 'image_status', 'image_path', 'clip_status', 'clip_path', 'clip_parts',
        'audio_path', 'audio_key', 'audio_ms', 'audio_lines', 'clip_stage', 'synced_audio_path', 'sync_warning', 'clip_request_id', 'clip_error', 'video_path', 'request_id', 'error',
        'dub_vi_path', 'dub_vi_key', 'dub_vi_lines',
    ];

    protected function casts(): array
    {
        return ['character_ids' => 'array', 'dialogue' => 'array', 'clip_parts' => 'array', 'audio_lines' => 'array', 'dub_vi_lines' => 'array', 'continues_previous' => 'boolean', 'animate' => 'boolean', 'lip_sync' => 'boolean'];
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(SeriesLocation::class, 'series_location_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ShotImage::class);
    }
}
