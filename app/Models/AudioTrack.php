<?php

namespace App\Models;

use App\Jobs\AnalyzeVocalsJob;
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

    protected $fillable = ['name', 'path', 'source_url', 'mime', 'size_bytes', 'duration_ms', 'rights_confirmed_at', 'vocal_status', 'vocal_analysis', 'vocal_error', 'lyrics', 'language', 'song_info'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'duration_ms' => 'integer',
            'rights_confirmed_at' => 'datetime',
            'vocal_analysis' => 'array',
            'song_info' => 'array',
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

    /** Bắt đầu phân tích giọng hát (một lần; lỗi thì gọi lại để thử lại). */
    public function analyzeVocals(): void
    {
        if ($this->isPlayable() && ! in_array($this->vocal_status, ['pending', 'ready'], true)) {
            $this->update(['vocal_status' => 'pending', 'vocal_error' => null]);
            AnalyzeVocalsJob::dispatch($this);
        }
    }

    /** Có file nhạc để ghép vào video (không chỉ là link). */
    public function isPlayable(): bool
    {
        return $this->path !== null;
    }
}
