<?php

namespace App\Models;

use Database\Factories\TopicShortSceneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một cảnh: lời kể + ảnh. image_source: web (wiki), muse, upload.
 * image_status: none, searching (đang tìm wiki), pending (Muse đang vẽ), ready, failed.
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
        'image_path', 'image_url', 'image_error', 'request_id', 'candidates', 'fact_ids', 'fact_check', 'mood', 'panels',
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
        ];
    }

    public function topicShort(): BelongsTo
    {
        return $this->belongsTo(TopicShort::class);
    }
}
