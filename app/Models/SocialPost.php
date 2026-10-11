<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Một lần đăng video lên mạng xã hội: pending → uploading → processing → published / failed. */
class SocialPost extends Model
{
    protected $fillable = [
        'video_project_id', 'render_id', 'social_account_id', 'provider', 'account_name', 'caption',
        'status', 'external_id', 'permalink', 'error', 'published_at',
    ];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function videoProject(): BelongsTo
    {
        return $this->belongsTo(VideoProject::class);
    }

    public function render(): BelongsTo
    {
        return $this->belongsTo(Render::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function isBusy(): bool
    {
        return in_array($this->status, ['pending', 'uploading', 'processing'], true);
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'account_name' => $this->account_name,
            'caption' => $this->caption,
            'status' => $this->status,
            'permalink' => $this->permalink,
            'error' => $this->error,
            'published_at' => $this->published_at,
            'created_at' => $this->created_at,
        ];
    }
}
