<?php

namespace App\Models;

use App\Jobs\AnalyzeSampleVideoJob;
use App\Services\Video\ReferenceClip;
use Database\Factories\SampleVideoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Video mẫu (điệu nhảy, cách tạo dáng...) người dùng tải lên để sao chép chuyển động. */
class SampleVideo extends Model
{
    /** @use HasFactory<SampleVideoFactory> */
    use HasFactory;

    protected $fillable = [
        'name', 'path', 'thumbnail_path', 'reference_path', 'analysis_status', 'motion_analysis', 'shots', 'analysis_error', 'has_audio', 'audio_track_id', 'mime', 'size_bytes', 'duration_ms', 'width', 'height',
        'source_url', 'notes', 'rights_confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'duration_ms' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'rights_confirmed_at' => 'datetime',
            'motion_analysis' => 'array',
            'shots' => 'array',
            'has_audio' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Bài nhạc tách từ tiếng của video mẫu (thư viện Âm thanh). */
    public function audioTrack(): BelongsTo
    {
        return $this->belongsTo(AudioTrack::class);
    }

    /**
     * Dòng thời gian chuyển động đã phân tích, dạng chữ để đưa vào prompt Muse.
     */
    /** Shot dài hơn (giây) được chia thành vài cảnh cùng bộ đồ: Muse chỉ làm clip ngắn và cảnh dài dễ chán. */
    public const PIECE_MAX = 6.0;

    /** Video nhiều shot: tối đa số cảnh này. */
    public const MAX_PIECES = 12;

    /**
     * Các đoạn video mẫu ứng với từng cảnh khi video có nhiều shot: mỗi shot một (hoặc vài, nếu dài) cảnh;
     * "shot" là số thứ tự shot để các cảnh cùng shot mặc cùng một bộ đồ. Rỗng khi video chỉ có một shot.
     *
     * @return list<array{start: float, end: float, shot: int}>
     */
    public function pieces(): array
    {
        $shots = is_array($this->shots) ? $this->shots : [];
        if (count($shots) < 2) {
            return [];
        }

        $pieces = [];
        foreach ($shots as $index => $shot) {
            $start = (float) $shot['start'];
            $end = min((float) $shot['end'], (float) AnalyzeSampleVideoJob::MAX_SECONDS);
            if ($end - $start < 0.3) {
                continue;
            }
            $count = (int) ceil(($end - $start) / self::PIECE_MAX - 0.001);
            $length = ($end - $start) / $count;
            for ($i = 0; $i < $count; $i++) {
                $pieces[] = ['start' => round($start + $i * $length, 2), 'end' => round($start + ($i + 1) * $length, 2), 'shot' => $index];
            }
        }

        return array_slice($pieces, 0, self::MAX_PIECES);
    }

    /** Số shot (cắt cảnh) của video mẫu, 1 khi không cắt cảnh. */
    public function shotCount(): int
    {
        return max(1, is_array($this->shots) ? count($this->shots) : 0);
    }

    /** Số giây của video mẫu dùng để sao chép (các cảnh lần lượt lấy từng đoạn trong khoảng này). */
    public function motionSpan(): float
    {
        return min(AnalyzeSampleVideoJob::MAX_SECONDS, ($this->duration_ms ?: ReferenceClip::MAX_SECONDS * 1000) / 1000);
    }

    /**
     * Dòng thời gian chuyển động; có $from/$to thì chỉ lấy các nhịp trong đoạn đó, tính lại thời điểm từ 0
     * (khớp với đoạn video mẫu gửi kèm cho cảnh).
     */
    public function motionTimeline(?float $from = null, ?float $to = null): ?string
    {
        $analysis = $this->motion_analysis;
        if (! is_array($analysis) || empty($analysis['steps'])) {
            return null;
        }

        $from ??= 0.0;
        $steps = collect($analysis['steps'])
            ->filter(fn (array $step) => $to === null || (($step['end'] ?? 0) > $from + 0.05 && ($step['start'] ?? 0) < $to - 0.05))
            ->map(fn (array $step) => sprintf(
                '%.1f–%.1fs: %s',
                max(0, ($step['start'] ?? 0) - $from),
                max(0, ($to === null ? ($step['end'] ?? 0) : min($to, $step['end'] ?? 0)) - $from),
                rtrim(trim((string) ($step['motion'] ?? '')), '.'),
            ))
            ->implode('; ');
        if ($steps === '') {
            return null;
        }

        return "Motion timeline to reproduce exactly: {$steps}."
            .(! empty($analysis['tempo']) ? " Tempo: {$analysis['tempo']}." : '')
            .(! empty($analysis['framing']) ? " Camera: {$analysis['framing']}." : '');
    }
}
