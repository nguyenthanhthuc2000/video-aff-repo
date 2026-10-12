<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Một lần tạo keyframe cho shot (giữ các bản cũ để chọn lại). */
class ShotImage extends Model
{
    protected $fillable = ['shot_id', 'path', 'prompt', 'provider', 'request_id', 'approved'];

    protected function casts(): array
    {
        return ['approved' => 'boolean'];
    }

    public function shot(): BelongsTo
    {
        return $this->belongsTo(Shot::class);
    }
}
