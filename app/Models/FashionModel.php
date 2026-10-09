<?php

namespace App\Models;

use Database\Factories\FashionModelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Người mẫu dùng làm ảnh nền để "mặc" sản phẩm.
 * Tên FashionModel để không nhầm với Eloquent Model.
 */
class FashionModel extends Model
{
    /** @use HasFactory<FashionModelFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'photo_path',
        'consent_at',
    ];

    protected function casts(): array
    {
        return [
            'consent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function videoProjects(): HasMany
    {
        return $this->hasMany(VideoProject::class);
    }

    public function hasConsent(): bool
    {
        return $this->consent_at !== null;
    }
}
