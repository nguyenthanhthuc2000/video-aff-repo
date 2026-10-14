<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Đoạn hook 15–40 giây đã lưu của một bài hát (điệp khúc / hook / tự chọn), dùng làm nhạc cho Shorts. */
class SongClip extends Model
{
    protected $fillable = ['user_id', 'audio_track_id', 'virtual_band_id', 'name', 'start_ms', 'end_ms', 'kind'];

    protected function casts(): array
    {
        return ['start_ms' => 'integer', 'end_ms' => 'integer'];
    }

    public function audioTrack(): BelongsTo
    {
        return $this->belongsTo(AudioTrack::class);
    }

    public function band(): BelongsTo
    {
        return $this->belongsTo(VirtualBand::class, 'virtual_band_id');
    }

    public function seconds(): int
    {
        return (int) round(($this->end_ms - $this->start_ms) / 1000);
    }
}
