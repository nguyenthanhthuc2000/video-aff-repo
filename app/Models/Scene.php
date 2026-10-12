<?php

namespace App\Models;

use App\Enums\SceneClipStatus;
use App\Enums\SceneImageStatus;
use App\Enums\ScenePhase;
use App\Jobs\GenerateSceneClipJob;
use App\Services\Video\ReferenceClip;
use App\Support\Media;
use App\Support\MusePlan;
use Database\Factories\SceneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Scene extends Model
{
    /** @use HasFactory<SceneFactory> */
    use HasFactory;

    protected $fillable = [
        'position',
        'phase',
        'angle',
        'background_id',
        'product_id',
        'product_image_id',
        'outfit_color',
        'duration_ms',
        'visual',
        'on_screen_text',
        'voiceover',
        'image_prompt',
        'clip_path',
        'clip_parts',
        'clip_status',
        'clip_error',
        'clip_request_id',
        'clip_prompt',
        'clip_started_at',
        'clip_completed_at',
        'sample_video_id',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'duration_ms' => 'integer',
            'clip_status' => SceneClipStatus::class,
            'clip_parts' => 'array',
            'clip_started_at' => 'datetime',
            'clip_completed_at' => 'datetime',
            'phase' => ScenePhase::class,
        ];
    }

    /** Bối cảnh người dùng cung cấp cho cảnh (null = AI tự nghĩ bối cảnh theo prompt). */
    public function background(): BelongsTo
    {
        return $this->belongsTo(Background::class);
    }

    /** Sản phẩm (bộ đồ) riêng của cảnh khi mỗi cảnh một bộ đồ; null = sản phẩm của dự án. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Ảnh sản phẩm đúng màu / mẫu của cảnh; null = mọi ảnh sản phẩm. */
    public function productImage(): BelongsTo
    {
        return $this->belongsTo(ProductImage::class);
    }

    /** Sản phẩm cảnh này mặc. */
    public function outfitProduct(): Product
    {
        return $this->product ?? $this->videoProject->product;
    }

    /** Cùng bộ đồ với cảnh kia (cùng sản phẩm, cùng ảnh / màu): trong một cảnh quay liền thì cùng một đoạn. */
    public function sameOutfitAs(self $other): bool
    {
        return $this->product_id === $other->product_id
            && $this->product_image_id === $other->product_image_id
            && $this->outfit_color === $other->outfit_color;
    }

    /** Câu tả sản phẩm trong prompt ảnh / clip, kèm màu đã chọn. */
    public function productLine(): string
    {
        $line = ProductCategory::productLine($this->outfitProduct());
        if (filled($this->outfit_color)) {
            $line .= " Use exactly the \"{$this->outfit_color}\" color / variant of this product".($this->product_image_id ? ', as in the attached product photo' : '').'.';
        }

        return $line;
    }

    /** Có bộ đồ riêng (khác sản phẩm của dự án hoặc chọn màu / ảnh). */
    public function hasOutfit(): bool
    {
        return $this->product_id !== null || $this->product_image_id !== null || filled($this->outfit_color);
    }

    /** @return array{product_id: int, product_name: string, product_image_id: ?int, image_url: ?string, color: ?string} */
    public function outfit(): array
    {
        $product = $this->outfitProduct();
        $image = $this->productImage ?? $product->images()->orderByDesc('is_primary')->orderBy('id')->first();

        return [
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_image_id' => $this->product_image_id,
            'image_url' => Media::url($image?->path),
            'color' => $this->outfit_color,
        ];
    }

    public function videoProject(): BelongsTo
    {
        return $this->belongsTo(VideoProject::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(SceneImage::class)->latest('id');
    }

    /** Ảnh mới nhất của cảnh (mỗi lần tạo lại là một dòng mới). */
    public function latestImage(): HasOne
    {
        return $this->hasOne(SceneImage::class)->latestOfMany();
    }

    public function approvedImage(): HasOne
    {
        return $this->hasOne(SceneImage::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->where('status', SceneImageStatus::Approved),
        );
    }

    /** Đưa việc tạo clip chuyển động (muse.ai) vào hàng đợi. */
    /**
     * Ảnh cảnh đổi (tạo lại, tải lên, duyệt ảnh khác): clip cũ / clip đang tạo không còn khớp ảnh nên bỏ;
     * job đang hỏi kết quả tự dừng vì cảnh không còn chờ clip. Một cảnh quay liền: các nhịp sau nối từ clip này nên bỏ theo.
     */
    public function discardClip(): void
    {
        $project = $this->videoProject;
        // Quay liền: các nhịp sau trong cùng đoạn (đến cảnh mở đầu đoạn kế tiếp, tức bộ đồ kế tiếp).
        $nextTake = $project->one_take ? $project->takeStarts()->first(fn (Scene $scene) => $scene->position > $this->position)?->position : null;
        $scenes = $project->one_take
            ? $project->scenes()->where('position', '>=', $this->position)->when($nextTake, fn ($q) => $q->where('position', '<', $nextTake))->get()
            : collect([$this]);

        foreach ($scenes as $scene) {
            if ($scene->clip_path === null && $scene->clip_status === null && $scene->clip_request_id === null) {
                continue;
            }
            Media::delete($scene->clip_path);
            $scene->dropClipParts();
            $scene->update(['clip_path' => null, 'clip_status' => null, 'clip_error' => null, 'clip_request_id' => null, 'clip_started_at' => null, 'clip_completed_at' => null]);
            if (! $scene->is($this)) {
                // Khung mở đầu lấy từ clip cảnh trước: bỏ, sẽ có lại khi clip cảnh trước tạo xong.
                $scene->images()->where('provider', 'one_take')->where('status', SceneImageStatus::Approved)->update(['status' => SceneImageStatus::Rejected]);
            }
        }
    }

    public function queueClip(): void
    {
        $this->dropClipParts();
        $this->update(['clip_status' => SceneClipStatus::Pending, 'clip_error' => null, 'clip_request_id' => null]);
        GenerateSceneClipJob::dispatch($this);
    }

    /** Bỏ các đoạn đã làm dở của shot dài (làm lại từ đoạn đầu). */
    public function dropClipParts(): void
    {
        foreach ($this->clip_parts ?? [] as $part) {
            Media::delete($part['path'] ?? null);
        }
        if ($this->clip_parts !== null) {
            $this->update(['clip_parts' => null]);
        }
    }

    /** Video mẫu riêng của cảnh (null = theo dự án). */
    public function sampleVideo(): BelongsTo
    {
        return $this->belongsTo(SampleVideo::class);
    }

    /** Video mẫu dùng để sao chép chuyển động cho cảnh: của cảnh, không có thì của dự án. */
    public function motionReference(): ?SampleVideo
    {
        return $this->sampleVideo ?? $this->videoProject->sampleVideo;
    }

    /**
     * Đoạn video mẫu cảnh này sao chép (giây). Các cảnh cùng video mẫu lần lượt lấy đoạn tiếp theo theo thời lượng cảnh,
     * nên cả video làm trọn điệu nhảy thay vì cảnh nào cũng lặp lại mấy giây đầu. Hết video mẫu thì quay lại từ đầu.
     *
     * @return array{start: float, length: float}
     */
    public function motionSegment(): array
    {
        $sample = $this->motionReference();
        // Phân cảnh Muse ghi rõ đoạn video mẫu của cảnh.
        if ($sample !== null && ($range = MusePlan::sampleRange($this)) !== null) {
            return ['start' => round($range['start'], 1), 'length' => round(max(1.0, $range['end'] - $range['start']), 1)];
        }
        // Video mẫu nhiều shot: cảnh thứ k (trong các cảnh cùng video mẫu) sao chép đúng đoạn thứ k.
        // (Một cảnh quay liền thì không theo shot, các nhịp sao chép lần lượt theo thời gian.)
        if ($sample !== null && ! $this->videoProject->one_take && ($pieces = $sample->pieces()) !== []) {
            $index = $this->videoProject->scenes()
                ->where('position', '<', $this->position)
                ->get()
                ->filter(fn (Scene $scene) => $scene->motionReference()?->id === $sample->id)
                ->count();
            $piece = $pieces[$index % count($pieces)];

            return ['start' => round($piece['start'], 1), 'length' => round(min(ReferenceClip::MAX_SECONDS, max(1.0, $piece['end'] - $piece['start'])), 1)];
        }

        $span = $sample?->motionSpan() ?? ReferenceClip::MAX_SECONDS;
        // Thêm 0,5 giây cho đoạn chuyển cảnh, tối thiểu 2 giây để Muse kịp làm động tác.
        $length = min($span, ReferenceClip::MAX_SECONDS, max(2.0, $this->duration_ms / 1000 + 0.5));
        if ($sample === null || $span <= $length) {
            return ['start' => 0.0, 'length' => round($length, 1)];
        }

        $offset = $this->videoProject->scenes()
            ->where('position', '<', $this->position)
            ->get()
            ->filter(fn (Scene $scene) => $scene->motionReference()?->id === $sample->id)
            ->sum('duration_ms') / 1000;

        $start = fmod($offset, $span);
        if ($start + $length > $span) {
            // Còn hơn nửa đoạn thì làm nốt phần cuối điệu nhảy, không thì quay lại từ đầu.
            $start = $span - $start >= $length / 2 ? $span - $length : 0.0;
        }

        return ['start' => round($start, 1), 'length' => round($length, 1)];
    }
}
