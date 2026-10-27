<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'english_profile',
        'logo_path',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
            'credit_balance' => 'integer',
            'blocked_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function fashionModels(): HasMany
    {
        return $this->hasMany(FashionModel::class);
    }

    public function sampleVideos(): HasMany
    {
        return $this->hasMany(SampleVideo::class);
    }

    /** Tài khoản mạng xã hội đã kết nối để đăng video. */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function virtualBands(): HasMany
    {
        return $this->hasMany(VirtualBand::class);
    }

    public function songClips(): HasMany
    {
        return $this->hasMany(SongClip::class);
    }

    public function songDrafts(): HasMany
    {
        return $this->hasMany(SongDraft::class);
    }

    public function audioTracks(): HasMany
    {
        return $this->hasMany(AudioTrack::class);
    }

    public function backgrounds(): HasMany
    {
        return $this->hasMany(Background::class);
    }

    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    public function aiUsageLogs(): HasMany
    {
        return $this->hasMany(AiUsageLog::class);
    }

    public function videoProjects(): HasMany
    {
        return $this->hasMany(VideoProject::class);
    }

    public function series(): HasMany
    {
        return $this->hasMany(Series::class);
    }

    public function characterPortraits(): HasMany
    {
        return $this->hasMany(CharacterPortrait::class);
    }

    public function topicShorts(): HasMany
    {
        return $this->hasMany(TopicShort::class);
    }

    public function topicSeries(): HasMany
    {
        return $this->hasMany(TopicSeries::class);
    }

    public function mangaScans(): HasMany
    {
        return $this->hasMany(MangaScan::class);
    }

    public function mangaPanels(): HasMany
    {
        return $this->hasMany(MangaPanel::class);
    }

    public function pronunciations(): HasMany
    {
        return $this->hasMany(Pronunciation::class);
    }

    public function loreFacts(): HasMany
    {
        return $this->hasMany(LoreFact::class);
    }

    public function englishLessons(): HasMany
    {
        return $this->hasMany(EnglishLesson::class)->orderBy('day');
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    public function isBlocked(): bool
    {
        return $this->blocked_at !== null;
    }
}
