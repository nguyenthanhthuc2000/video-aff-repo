<?php

namespace App\Models;

use Database\Factories\TopicShortSceneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một cảnh: lời kể + ảnh. image_source: web (wiki), muse, upload.
 * image_status: none, searching (đang tìm wiki), pending (Muse đang vẽ), ready, failed.
 * voice_path: giọng tự thu (mp3...) người dùng tải lên cho cảnh, dùng thay giọng máy khi dựng video.
 * manga_panels: id khung manga thật (kho khung manga) ghép thành ảnh cảnh, ưu tiên hơn panels.
 * panels: trang manga 1–3 khung [{character (khoá thư viện), pose (E1–E9, A1–A6, S1–S4), camera}]; image_source "page" khi ảnh là trang đã ghép.
 */
class TopicShortScene extends Model
{
    /** @use HasFactory<TopicShortSceneFactory> */
    use HasFactory;

    /** Kiểm chứng chưa chạy / lỗi / lời kể đã sửa: người dùng bấm Kiểm chứng lại. */
    public const UNCHECKED = ['status' => 'error', 'note' => 'Chưa kiểm chứng (lời kể đã sửa hoặc kiểm chứng lỗi), bấm Kiểm chứng lại.'];

    protected $fillable = [
        'position', 'narration', 'on_screen_text', 'image_query', 'image_prompt', 'characters', 'expression', 'image_source', 'image_status',
        'image_path', 'image_url', 'image_error', 'muse_refusal', 'muse_fix_prompt', 'request_id', 'candidates', 'fact_ids', 'fact_check', 'mood', 'panels', 'manga_panels', 'voice_path', 'voice_ms',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'candidates' => 'array',
            'characters' => 'array',
            'fact_ids' => 'array',
            'fact_check' => 'array',
            'panels' => 'array',
            'manga_panels' => 'array',
            'voice_ms' => 'integer',
        ];
    }

    public function topicShort(): BelongsTo
    {
        return $this->belongsTo(TopicShort::class);
    }
}
