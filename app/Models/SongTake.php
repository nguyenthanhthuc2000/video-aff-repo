<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Một bản nhạc đã tạo cho bài hát (tạo bằng API hoặc tải lên); bài có thể có nhiều bản, chọn một bản dùng (song_drafts.audio_track_id). */
class SongTake extends Model
{
    protected $fillable = ['song_draft_id', 'audio_track_id', 'provider'];

    public function draft(): BelongsTo
    {
        return $this->belongsTo(SongDraft::class, 'song_draft_id');
    }

    public function audioTrack(): BelongsTo
    {
        return $this->belongsTo(AudioTrack::class);
    }
}
