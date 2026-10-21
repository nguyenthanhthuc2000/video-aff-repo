<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ảnh một nhân vật Ngọc Rồng với một biểu cảm (thư viện dùng làm ảnh mẫu / ảnh cảnh cho Shorts chủ đề). */
class CharacterPortrait extends Model
{
    protected $fillable = ['character', 'expression', 'prompt', 'attempts', 'status', 'source', 'request_id', 'image_path', 'error'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Ảnh tốt nhất cho nhân vật + biểu cảm: đúng biểu cảm, không có thì mặt thường (E1). */
    public static function best(int $userId, string $character, ?string $expression): ?self
    {
        return self::where('user_id', $userId)->where('character', $character)->where('status', 'ready')->whereNotNull('image_path')
            ->whereIn('expression', array_unique(array_filter([$expression, 'E1'])))
            ->get()->sortBy(fn (self $p) => $p->expression === $expression ? 0 : 1)->first();
    }
}
