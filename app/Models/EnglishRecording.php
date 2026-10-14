<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Một lần người học đọc câu / nói (ghi âm trên trình duyệt), Whisper chép lời, điểm phát âm 0–10. */
class EnglishRecording extends Model
{
    protected $fillable = ['english_lesson_id', 'item', 'path', 'duration_ms', 'status', 'transcript', 'words', 'segments', 'score', 'error'];

    protected function casts(): array
    {
        return ['words' => 'array', 'segments' => 'array'];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(EnglishLesson::class, 'english_lesson_id');
    }
}
