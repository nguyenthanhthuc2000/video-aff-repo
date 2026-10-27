<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Một trang truyện người dùng tải lên. status: processing (đang cắt / Claude đang xem) → ready | failed. */
class MangaScan extends Model
{
    protected $fillable = ['chapter', 'name', 'image_path', 'status', 'error'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function panels(): HasMany
    {
        return $this->hasMany(MangaPanel::class)->orderBy('position');
    }
}
