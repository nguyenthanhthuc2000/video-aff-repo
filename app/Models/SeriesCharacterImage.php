<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Một ảnh của nhân vật: Muse vẽ (pending → ready / failed) hoặc tải lên (ready). */
class SeriesCharacterImage extends Model
{
    /** Loại ảnh nhân vật cho Muse vẽ. */
    public const KINDS = [
        'main' => 'Toàn thân, nhìn thẳng (ảnh chính)',
        'turnaround' => 'Bảng xoay: trước, nghiêng, sau',
        'expressions' => 'Bảng biểu cảm khuôn mặt',
        'custom' => 'Tự mô tả tư thế / hành động',
    ];

    protected $fillable = ['series_character_id', 'kind', 'source', 'path', 'prompt', 'status', 'request_id', 'error'];

    public function character(): BelongsTo
    {
        return $this->belongsTo(SeriesCharacter::class, 'series_character_id');
    }
}
