<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Một ngày học tiếng Anh (App\Services\English\Curriculum): bài 5 câu hoặc bài nói 30 giây, kèm video Shorts. */
class EnglishLesson extends Model
{
    public const KINDS = ['sentences' => '5 câu mỗi ngày', 'speaking' => 'Nói 30 giây'];

    protected $fillable = [
        'user_id', 'day', 'kind', 'level', 'topic', 'topic_vi', 'content', 'feedback', 'status', 'error',
        'video_status', 'video_error', 'video_options', 'video_path', 'thumbnail_path', 'video_rendered_at',
    ];

    protected function casts(): array
    {
        return ['content' => 'array', 'feedback' => 'array', 'video_options' => 'array', 'video_rendered_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recordings(): HasMany
    {
        return $this->hasMany(EnglishRecording::class);
    }

    /** Lần đọc tốt nhất của mỗi câu (điểm cao nhất, bằng điểm thì lần mới hơn). @return array<int, EnglishRecording> */
    public function bestRecordings(): array
    {
        return $this->recordings()->where('status', 'done')->get()
            ->groupBy('item')
            ->map(fn ($group) => $group->sortByDesc(fn (EnglishRecording $r) => [(int) $r->score, $r->id])->first())
            ->all();
    }
}
