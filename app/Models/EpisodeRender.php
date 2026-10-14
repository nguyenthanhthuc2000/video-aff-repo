<?php

namespace App\Models;

use App\Services\Film\EpisodeRenderer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Một bản đã xuất của tập (mỗi lần ghép thêm một bản, không thay bản cũ). */
class EpisodeRender extends Model
{
    protected $fillable = ['episode_id', 'lang', 'options', 'path', 'srt_path', 'thumbnail_path', 'thumbnail_options', 'art_path', 'art_status', 'art_request_id', 'art_error', 'duration_ms'];

    protected function casts(): array
    {
        return ['options' => 'array', 'thumbnail_options' => 'array'];
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function vertical(): bool
    {
        return str_starts_with((string) ($this->options['aspect'] ?? ''), '9:16');
    }

    public function silent(): bool
    {
        return $this->lang !== 'vi' && ! ($this->options['dialogue'] ?? true);
    }

    /** Mô tả ngắn: "Dọc 9:16 · không lời · tiếng Việt". */
    public function label(): string
    {
        $aspect = EpisodeRenderer::ASPECTS[$this->options['aspect'] ?? '16:9'] ?? 'Ngang 16:9 (YouTube)';

        return implode(' · ', array_filter([
            $aspect,
            $this->lang === 'vi' ? 'Lồng tiếng Việt, phụ đề tiếng Anh' : null,
            $this->silent() ? 'Không lời thoại' : null,
            ($this->options['burn_subtitles'] ?? false) && ! $this->silent() && $this->lang !== 'vi' ? 'Có phụ đề trong hình' : null,
        ]));
    }

    public function fileName(): string
    {
        $episode = $this->episode;

        return Str::slug($episode->series->title).'-tap-'.$episode->number.($this->lang === 'vi' ? '-tieng-viet' : '')
            .($this->vertical() ? '-doc' : '').($this->silent() ? '-khong-loi' : '').'-'.$this->id;
    }
}
