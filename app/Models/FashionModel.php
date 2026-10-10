<?php

namespace App\Models;

use App\Enums\FashionModelKind;
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

    protected $attributes = [
        'kind' => 'after',
    ];

    protected $fillable = [
        'name',
        'kind',
        'photo_path',
        'consent_at',
    ];

    protected function casts(): array
    {
        return [
            'consent_at' => 'datetime',
            'kind' => FashionModelKind::class,
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
