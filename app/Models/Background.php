<?php

namespace App\Models;

use App\Support\Media;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Bối cảnh người dùng cung cấp (ảnh nền): AI đặt người mẫu vào đúng nơi này thay vì tự nghĩ bối cảnh. */
class Background extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'photo_path'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(Scene::class);
    }

    /** Tên kèm mô tả, dùng trong prompt viết kịch bản. */
    public function label(): string
    {
        return $this->description ? "{$this->name} ({$this->description})" : $this->name;
    }

    /** @return array{id: int, name: string, description: ?string, photo_url: ?string} */
    public function present(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'description' => $this->description, 'photo_url' => Media::url($this->photo_path)];
    }
}
