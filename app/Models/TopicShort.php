<?php

namespace App\Models;

use Database\Factories\TopicShortFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Video short theo chủ đề: Claude kể chuyện, ảnh từ wiki / Muse / tải lên, giọng AI, phụ đề (9:16, 30–60 giây). */
class TopicShort extends Model
{
    /** @use HasFactory<TopicShortFactory> */
    use HasFactory;

    public const LANGUAGES = ['vi' => 'Tiếng Việt', 'en' => 'English'];

    protected $fillable = [
        'topic', 'language', 'target_seconds', 'wiki', 'status', 'error', 'title', 'content', 'characters', 'art_style',
        'video_status', 'video_error', 'video_options', 'video_path', 'video_ms',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'characters' => 'array',
            'video_options' => 'array',
            'target_seconds' => 'integer',
            'video_ms' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(TopicShortScene::class)->orderBy('position');
    }

    /** Có cảnh đang tìm / vẽ ảnh: trang tự làm mới. */
    public function imagesBusy(): bool
    {
        return $this->scenes()->whereIn('image_status', ['searching', 'pending'])->exists();
    }

    public function allImagesReady(): bool
    {
        return $this->scenes()->exists() && $this->scenes()->where('image_status', '!=', 'ready')->doesntExist();
    }
}
