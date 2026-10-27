<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một khung cắt từ trang truyện. characters: khoá nhân vật Ngọc Rồng (DragonBall::CHARACTERS); description: Claude tả chuyện trong khung;
 * mood: tense, sad, epic, funny, calm; useful = false khi khung chỉ có chữ / hiệu ứng, không dùng làm ảnh cảnh.
 */
class MangaPanel extends Model
{
    protected $fillable = ['user_id', 'manga_scan_id', 'position', 'image_path', 'width', 'height', 'characters', 'description', 'mood', 'useful', 'tagged_at'];

    protected function casts(): array
    {
        return [
            'characters' => 'array',
            'useful' => 'boolean',
            'tagged_at' => 'datetime',
            'position' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(MangaScan::class, 'manga_scan_id');
    }
}
