<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Nhân vật của bộ phim: mô tả ngoại hình cố định + ảnh chính (đã chốt thì dùng làm tham chiếu cho mọi shot). */
class SeriesCharacter extends Model
{
    public const ROLES = ['main' => 'Nhân vật chính', 'friend' => 'Bạn đồng hành', 'family' => 'Gia đình', 'mentor' => 'Người hướng dẫn', 'rival' => 'Đối thủ', 'pet' => 'Thú cưng', 'side' => 'Phụ'];

    protected $fillable = [
        'series_id', 'position', 'name', 'role', 'age', 'personality', 'appearance', 'voice',
        'main_image_path', 'sheet_path', 'status', 'request_id', 'error', 'locked_at',
    ];

    protected function casts(): array
    {
        return ['voice' => 'array', 'locked_at' => 'datetime'];
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(SeriesCharacterImage::class)->latest('id');
    }
}
