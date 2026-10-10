<?php

namespace App\Models;

use Database\Factories\AudioTrackFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Nhạc nền trong thư viện của người dùng. */
class AudioTrack extends Model
{
    /** @use HasFactory<AudioTrackFactory> */
    use HasFactory;

    protected $fillable = ['name', 'path', 'source_url', 'mime', 'size_bytes', 'duration_ms', 'rights_confirmed_at'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'duration_ms' => 'integer',
            'rights_confirmed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function videoProjects(): HasMany
    {
        return $this->hasMany(VideoProject::class);
    }

    /** Có file nhạc để ghép vào video (không chỉ là link). */
    public function isPlayable(): bool
    {
        return $this->path !== null;
    }
}
