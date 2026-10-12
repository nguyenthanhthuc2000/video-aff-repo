<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Một tập phim: draft → writing → storyboard → keyframing → animatic_ready → animating → mixing → done (failed). */
class Episode extends Model
{
    public const STATUSES = [
        'draft' => 'Chưa viết', 'writing' => 'Đang viết', 'storyboard' => 'Có kịch bản', 'keyframing' => 'Đang vẽ khung hình', 'keyframed' => 'Có khung hình',
        'animatic_ready' => 'Có bản xem trước', 'animating' => 'Đang làm clip', 'mixing' => 'Đang ghép', 'done' => 'Xong', 'failed' => 'Lỗi',
    ];

    protected $fillable = [
        'series_id', 'number', 'title', 'synopsis', 'lesson', 'character_ids', 'outline', 'summary', 'status',
        'animatic_path', 'render_path', 'duration_ms', 'error',
    ];

    protected function casts(): array
    {
        return ['outline' => 'array', 'character_ids' => 'array'];
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class);
    }

    /** Hết shot đang vẽ: đủ khung hình thì "Có khung hình", còn shot lỗi / chưa vẽ thì về "Có kịch bản". */
    public function syncKeyframeStatus(): void
    {
        if (! in_array($this->fresh()->status, ['keyframing', 'storyboard', 'keyframed'], true) || $this->shots()->where('image_status', 'pending')->exists()) {
            return;
        }
        $this->update(['status' => $this->shots()->where('image_status', '!=', 'ready')->exists() ? 'storyboard' : 'keyframed']);
    }

    public function shots(): HasMany
    {
        return $this->hasMany(Shot::class)->orderBy('sequence')->orderBy('position');
    }
}
