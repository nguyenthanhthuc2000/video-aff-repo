<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tài khoản mạng xã hội đã kết nối để đăng video ("facebook": một Trang và mã của Trang; "tiktok": một tài khoản TikTok, mã sống 24 giờ kèm refresh token). */
class SocialAccount extends Model
{
    protected $fillable = ['user_id', 'provider', 'external_id', 'name', 'avatar_url', 'access_token', 'refresh_token', 'token_expires_at', 'refresh_expires_at'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'refresh_expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array{id: int, provider: string, name: string, external_id: string, avatar_url: ?string, expired: bool} */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'name' => $this->name,
            'external_id' => $this->external_id,
            'avatar_url' => $this->avatar_url,
            // Hết hạn refresh token (TikTok, 365 ngày): phải kết nối lại.
            'expired' => $this->refresh_expires_at !== null && $this->refresh_expires_at->isPast(),
        ];
    }
}
