<?php

namespace App\Models;

use App\Enums\SeriesStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Bộ phim hoạt hình nhiều tập (docs/phim-hoat-hinh.md). */
class Series extends Model
{
    protected $table = 'series';

    /** Câu phong cách mặc định (đã chốt: 2D anime kiểu phim truyền hình). */
    public const DEFAULT_STYLE = '2D anime TV-series style, clean bold line art, flat cel-shaded colors, soft simple shading, bright colorful backgrounds';

    public const GENRES = [
        'adventure' => 'Phiêu lưu',
        'mystery' => 'Bí ẩn / phá án nhẹ',
        'comedy' => 'Hài, tình bạn',
        'science' => 'Khoa học vui',
        'learning' => 'Học kiến thức (màu, số, động vật...)',
        'fairy_tale' => 'Cổ tích kể lại',
        'moral' => 'Truyện có bài học',
    ];

    /** Thứ tiếng phụ đề / tiêu đề thêm cho người xem nước ngoài. */
    public const SUBTITLE_LANGUAGES = [
        'es' => 'Tây Ban Nha', 'pt' => 'Bồ Đào Nha', 'hi' => 'Hindi', 'id' => 'Indonesia', 'ar' => 'Ả Rập',
        'fr' => 'Pháp', 'de' => 'Đức', 'vi' => 'Tiếng Việt',
    ];

    public const AGE_MIN = 3;

    public const AGE_MAX = 17;

    protected $fillable = [
        'user_id', 'title', 'idea', 'genre', 'age_min', 'age_max', 'made_for_kids', 'style', 'aspect_ratio',
        'episode_count', 'episode_seconds', 'language', 'subtitle_languages', 'intro_path', 'outro_path',
        'bible', 'status', 'error', 'autopilot',
    ];

    protected function casts(): array
    {
        return [
            'status' => SeriesStatus::class,
            'bible' => 'array',
            'subtitle_languages' => 'array',
            'made_for_kids' => 'boolean',
            'autopilot' => 'boolean',
        ];
    }

    /** YouTube: tuổi nhỏ nhất dưới 13 thì bắt buộc đánh dấu "Dành cho trẻ em" (COPPA). */
    public static function madeForKids(int $ageMin): bool
    {
        return $ageMin < 13;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function characters(): HasMany
    {
        return $this->hasMany(SeriesCharacter::class)->orderBy('position');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(SeriesLocation::class)->orderBy('position');
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class)->orderBy('number');
    }

    public function isBusy(): bool
    {
        return in_array($this->status, [SeriesStatus::Planning, SeriesStatus::Designing], true);
    }

    /** Đã chốt nhân vật / bối cảnh nào thì không cho Claude lên kế hoạch lại (sẽ thay hết). */
    public function hasLockedDesigns(): bool
    {
        return $this->characters()->whereNotNull('locked_at')->exists() || $this->locations()->whereNotNull('locked_at')->exists();
    }
}
