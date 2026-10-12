<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Một shot của tập: bối cảnh, nhân vật, góc máy, hành động, lời thoại → keyframe → clip. */
class Shot extends Model
{
    protected $fillable = [
        'episode_id', 'sequence', 'position', 'series_location_id', 'character_ids', 'camera', 'action', 'dialogue',
        'duration_ms', 'continues_previous', 'image_status', 'image_path', 'clip_status', 'clip_path', 'clip_parts',
        'audio_path', 'request_id', 'error',
    ];

    protected function casts(): array
    {
        return ['character_ids' => 'array', 'dialogue' => 'array', 'clip_parts' => 'array', 'continues_previous' => 'boolean'];
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
