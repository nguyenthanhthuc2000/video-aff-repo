<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bối cảnh lặp lại của bộ phim (nhà, lớp học...): mô tả + ảnh nền đã chốt. */
class SeriesLocation extends Model
{
    protected $fillable = ['series_id', 'position', 'name', 'description', 'image_path', 'status', 'request_id', 'error', 'locked_at'];

    protected function casts(): array
    {
        return ['locked_at' => 'datetime'];
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class);
    }
}
