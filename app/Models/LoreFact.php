<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Một chi tiết tư liệu đã duyệt (sự kiện / câu thoại / giả thuyết), dùng lại khi research video sau. kind: fact, quote, theory. */
class LoreFact extends Model
{
    public const KINDS = ['fact' => 'Sự kiện', 'quote' => 'Câu thoại', 'theory' => 'Giả thuyết'];

    protected $fillable = ['franchise', 'characters', 'era', 'kind', 'text', 'source_url', 'source_title'];

    protected function casts(): array
    {
        return ['characters' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
