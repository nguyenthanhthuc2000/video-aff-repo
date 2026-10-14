<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bài hát mới sáng tác từ bài tham khảo trên YouTube: fetching (đọc link) → profiling (Claude phân tích) → writing (Claude viết bài mới)
 * → ready (failed). Tạo nhạc: tự làm ở công cụ ngoài rồi tải lên, hoặc qua API (music_status). Bài tham khảo không được dùng trong MV.
 */
class SongDraft extends Model
{
    public const BUSY = ['fetching', 'profiling', 'writing'];

    protected $fillable = [
        'user_id', 'source_url', 'notes', 'status', 'error', 'reference', 'profile', 'song', 'overlap',
        'music_provider', 'music_status', 'music_request_id', 'music_error', 'audio_track_id',
    ];

    protected function casts(): array
    {
        return ['reference' => 'array', 'profile' => 'array', 'song' => 'array', 'overlap' => 'array'];
    }

    /** Độ dài bài cần tạo (giây): theo cấu trúc bài tham khảo, mặc định 3 phút. */
    public function targetSeconds(): int
    {
        $seconds = (int) collect($this->profile['structure'] ?? [])->sum('seconds');

        return $seconds > 0 ? max(60, min(300, $seconds)) : 180;
    }

    public function isBusy(): bool
    {
        return in_array($this->status, self::BUSY, true) || $this->music_status === 'pending';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function audioTrack(): BelongsTo
    {
        return $this->belongsTo(AudioTrack::class);
    }
}
