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

    /** Phong cách 3D cho truyện ngắn (tạo thiết kế gốc, không giống nhân vật có bản quyền). */
    public const STYLE_3D = 'original stylized 3D animated feature film look, cinematic composition, warm soft lighting, vivid colors, detailed soft fur and materials, consistent character models';

    /** Phong cách chọn nhanh khi tạo phim (vẫn sửa được thành câu riêng). */
    public const STYLE_PRESETS = [
        '2d' => ['label' => '2D anime kiểu phim truyền hình', 'style' => self::DEFAULT_STYLE],
        '3d' => ['label' => '3D stylized kiểu phim chiếu rạp', 'style' => self::STYLE_3D],
    ];

    /** Loại phim: nhiều tập có lời thoại (YouTube), hoặc truyện ngắn không thoại khung dọc (kể bằng hành động, biểu cảm, âm thanh). */
    public const FORMATS = [
        'episodic' => 'Phim nhiều tập có lời thoại (YouTube)',
        'silent_short' => 'Truyện ngắn không thoại 9:16 (TikTok, Reels, Shorts)',
    ];

    /** Truyện ngắn: số shot làm clip AI mỗi tập (còn lại dùng ảnh tĩnh + chuyển động máy + âm thanh). */
    public const CLIP_BUDGETS = [
        1 => 'Tiết kiệm: 1 clip AI / tập, còn lại ảnh tĩnh + chuyển máy',
        2 => 'Tiết kiệm: 2 clip AI / tập',
        3 => 'Cân bằng: 3 clip AI / tập (khuyến nghị thử nghiệm)',
        5 => 'Cao: mọi shot là clip AI',
    ];

    /** Thể loại từng tập của truyện ngắn (để luân phiên, so sánh tín hiệu). */
    public const KINDS = ['heartwarming' => 'Cảm động', 'funny' => 'Hài hước', 'magical' => 'Kỳ diệu', 'adventure' => 'Phiêu lưu'];

    /** Người xem mặc định của truyện ngắn không thoại. */
    public const SHORT_AUDIENCE = 'International audience on TikTok, Facebook Reels and YouTube Shorts, mainly 16–34; wholesome for all ages';

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

    /** Độ dài mỗi tập được chọn (giây): 30 giây cho Shorts tới 15 phút. */
    public const EPISODE_LENGTHS = [30, 60, 120, 180, 300, 480, 600, 900];

    public const AGE_MIN = 3;

    public const AGE_MAX = 17;

    protected $fillable = [
        'user_id', 'title', 'idea', 'format', 'seed_characters', 'seed_episodes', 'genre', 'age_min', 'age_max', 'audience', 'made_for_kids', 'style', 'aspect_ratio',
        'episode_count', 'episode_seconds', 'clip_budget', 'language', 'subtitle_languages', 'intro_path', 'outro_path',
        'bible', 'status', 'error', 'autopilot',
    ];

    protected function casts(): array
    {
        return [
            'status' => SeriesStatus::class,
            'bible' => 'array',
            'subtitle_languages' => 'array',
            'seed_characters' => 'array',
            'seed_episodes' => 'array',
            'made_for_kids' => 'boolean',
            'autopilot' => 'boolean',
        ];
    }

    /** YouTube: tuổi nhỏ nhất dưới 13 thì bắt buộc đánh dấu "Dành cho trẻ em" (COPPA). */
    public static function madeForKids(int $ageMin): bool
    {
        return $ageMin < 13;
    }

    /** Truyện ngắn không thoại: kể bằng hình, chuyển động, âm thanh; không đọc lời. */
    public function silent(): bool
    {
        return $this->format === 'silent_short';
    }

    /** Khung dọc 9:16: khung hình, clip và bản xuất đều dọc. */
    public function vertical(): bool
    {
        return $this->aspect_ratio === '9:16';
    }

    /** Dòng khổ khung đưa vào prompt ảnh / clip. */
    public function frameLine(): string
    {
        return $this->vertical() ? 'TALL VERTICAL 9:16 portrait frame' : 'WIDE HORIZONTAL 16:9 landscape frame';
    }

    /** Số clip AI mỗi tập của truyện ngắn. */
    public function clipBudget(): int
    {
        return $this->clip_budget ?: 3;
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
