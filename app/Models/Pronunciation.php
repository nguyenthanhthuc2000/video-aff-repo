<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cách đọc một tên riêng / thuật ngữ tiếng Anh bằng giọng Việt (chỉ đổi lời đọc, phụ đề giữ tên gốc). */
class Pronunciation extends Model
{
    protected $fillable = ['word', 'say'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
