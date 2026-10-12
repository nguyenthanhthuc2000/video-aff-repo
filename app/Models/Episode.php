<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Một tập phim: draft → writing → storyboard → keyframing → animatic_ready → animating → mixing → done (failed). */
class Episode extends Model
{
    public const STATUSES = [
        'draft' => 'Chưa viết', 'writing' => 'Đang viết', 'storyboard' => 'Có kịch bản', 'keyframing' => 'Đang vẽ khung hình', 'keyframed' => 'Có khung hình', 'animated' => 'Có video từng shot',
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
        $this->syncStatus();
    }

    /**
     * Trạng thái tập theo các shot (chỉ ở các bước khung hình / video): còn vẽ → đang vẽ, còn làm clip → đang làm clip,
     * shot nào cũng có video → có video từng shot, thiếu khung hình → có kịch bản, còn lại → có khung hình.
     */
    public function syncStatus(): void
    {
        if (! in_array($this->fresh()->status, ['storyboard', 'keyframing', 'keyframed', 'animating', 'animated'], true)) {
            return;
        }
        $shots = $this->shots()->get(['id', 'image_status', 'clip_status', 'video_path']);
        $this->update(['status' => match (true) {
            $shots->contains('image_status', 'pending') => 'keyframing',
            $shots->contains('clip_status', 'pending') => 'animating',
            $shots->isNotEmpty() && $shots->every(fn (Shot $shot) => $shot->video_path !== null) => 'animated',
            $shots->contains(fn (Shot $shot) => $shot->image_status !== 'ready') => 'storyboard',
            default => 'keyframed',
        }]);
    }

    public function shots(): HasMany
    {
        return $this->hasMany(Shot::class)->orderBy('sequence')->orderBy('position');
    }
}
