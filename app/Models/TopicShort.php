<?php

namespace App\Models;

use Database\Factories\TopicShortFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Video short theo chủ đề: Claude kể chuyện, ảnh từ wiki / Muse / tải lên / trang manga ghép từ thư viện, giọng AI, phụ đề (9:16, 30–60 giây).
 * status: researching (Claude tìm tư liệu web) → research_ready (chờ duyệt) → writing → ready | failed.
 */
class TopicShort extends Model
{
    /** @use HasFactory<TopicShortFactory> */
    use HasFactory;

    /** Số phần tối đa của một series. */
    public const MAX_SERIES_PARTS = 5;

    public const LANGUAGES = ['vi' => 'Tiếng Việt', 'en' => 'English'];

    /** Kiểu ảnh: Muse vẽ theo kiểu này, tìm wiki ưu tiên ảnh manga khi chọn truyện tranh. */
    public const IMAGE_STYLES = [
        'manga' => 'Truyện tranh đen trắng',
        'manga_color' => 'Truyện tranh màu',
        'anime' => 'Anime',
    ];

    /** Dạng short (học theo các kênh tóm tắt / phân tích anime). */
    public const STYLES = [
        'moment' => 'Tóm tắt khoảnh khắc',
        'facts' => 'Sự thật ít ai biết',
        'versus' => 'Giả thuyết đối đầu',
        'top5' => 'Top 5',
    ];

    protected $fillable = [
        'topic', 'language', 'style', 'image_style', 'target_seconds', 'wiki', 'status', 'error', 'title', 'content', 'characters', 'art_style',
        'video_status', 'video_error', 'video_options', 'video_path', 'video_ms',
        'use_research', 'research', 'research_notes', 'hooks', 'hook_index', 'series_parts', 'topic_series_id', 'part',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'characters' => 'array',
            'video_options' => 'array',
            'target_seconds' => 'integer',
            'video_ms' => 'integer',
            'use_research' => 'boolean',
            'research' => 'array',
            'hooks' => 'array',
            'hook_index' => 'integer',
            'series_parts' => 'integer',
            'part' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(TopicSeries::class, 'topic_series_id');
    }

    /** Chi tiết tư liệu người dùng giữ lại (đã duyệt) để Claude viết kịch bản. @return list<array> */
    public function approvedFacts(): array
    {
        return array_values(array_filter($this->research['facts'] ?? [], fn ($fact) => $fact['enabled'] ?? true));
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
