<?php

namespace App\Models;

use Database\Factories\TopicShortSceneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một cảnh: lời kể + ảnh. image_source: web (wiki), muse, upload.
 * image_status: none, searching (đang tìm wiki), pending (Muse đang vẽ), ready, failed.
 */
class TopicShortScene extends Model
{
    /** @use HasFactory<TopicShortSceneFactory> */
    use HasFactory;

    protected $fillable = [
        'position', 'narration', 'on_screen_text', 'image_query', 'image_prompt', 'image_source', 'image_status',
        'image_path', 'image_url', 'image_error', 'request_id', 'candidates',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'candidates' => 'array',
        ];
    }

    public function topicShort(): BelongsTo
    {
        return $this->belongsTo(TopicShort::class);
    }
}
