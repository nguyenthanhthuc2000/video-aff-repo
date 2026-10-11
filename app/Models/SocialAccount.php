<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tài khoản mạng xã hội đã kết nối để đăng video (provider "facebook": một Trang, mã truy cập của Trang). */
class SocialAccount extends Model
{
    protected $fillable = ['user_id', 'provider', 'external_id', 'name', 'access_token'];

    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return ['access_token' => 'encrypted'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array{id: int, provider: string, name: string, external_id: string} */
    public function present(): array
    {
        return ['id' => $this->id, 'provider' => $this->provider, 'name' => $this->name, 'external_id' => $this->external_id];
    }
}
