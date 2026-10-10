<?php

namespace App\Models;

use App\Enums\ProductImportStatus;
use App\Support\Media;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'shopee_url',
        'price',
        'details',
        'reviews',
        'import_status',
        'import_error',
        'import_snapshot',
        'images_approved_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'details' => 'array',
            'import_status' => ProductImportStatus::class,
            'import_snapshot' => 'array',
            'images_approved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    public function primaryImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->ofMany([
            'is_primary' => 'max',
            'id' => 'min',
        ]);
    }

    public function videoProjects(): HasMany
    {
        return $this->hasMany(VideoProject::class);
    }

    /** Đã chọn ảnh giữ lại (bắt buộc trước khi tạo video, phân cảnh). */
    public function imagesApproved(): bool
    {
        return $this->images_approved_at !== null;
    }

    /** Các màu ghi trong thông tin sản phẩm ("Đen, Trắng / Be"), để chọn màu cho từng bộ đồ. @return list<string> */
    public function colorOptions(): array
    {
        // Bỏ ghi chú trong ngoặc ("Hoa vàng (chỉ ghi trong mô tả...)") và đoạn quá dài (câu ghi chú, không phải tên màu).
        $text = preg_replace('/\([^)]*\)?/u', '', (string) ($this->details['colors'] ?? ''));
        $colors = preg_split('/\s*[,;\/|\n]\s*/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $colors = array_filter(array_map('trim', $colors), fn (string $color) => $color !== '' && mb_strlen($color) <= 30);

        return array_values(array_unique(array_slice($colors, 0, 20)));
    }

    /** Lựa chọn bộ đồ khi mỗi cảnh một bộ đồ: tên, màu, ảnh. @return array<string, mixed> */
    public function outfitOption(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'colors' => $this->colorOptions(),
            'images' => $this->images->sortByDesc('is_primary')->values()
                ->map(fn (ProductImage $image) => ['id' => $image->id, 'url' => Media::url($image->path)])->all(),
        ];
    }

    /**
     * Giữ lại các ảnh đã chọn (xoá ảnh còn lại), đặt ảnh chính và đánh dấu đã duyệt.
     *
     * @param  list<int>  $keepIds
     */
    public function approveImages(array $keepIds, int $primaryId): void
    {
        $removed = DB::transaction(function () use ($keepIds, $primaryId) {
            $removed = $this->images()->whereNotIn('id', $keepIds)->get();
            $this->images()->whereNotIn('id', $keepIds)->delete();
            $this->images()->update(['is_primary' => false]);
            $this->images()->whereKey($primaryId)->update(['is_primary' => true]);
            $this->update(['images_approved_at' => now()]);

            return $removed;
        });

        $removed->each(fn (ProductImage $image) => Media::delete($image->path));
    }

    /**
     * Lưu ảnh đã tải về (RemoteImages::download); ảnh đầu là ảnh chính nếu chưa có.
     *
     * @param  list<array{contents: string, extension: string}>  $images
     */
    public function addDownloadedImages(array $images): void
    {
        $hasPrimary = $this->images()->where('is_primary', true)->exists();

        foreach ($images as $index => $image) {
            $path = "products/{$this->id}/".Str::random(40).'.'.$image['extension'];
            Media::disk()->put($path, $image['contents']);

            $this->images()->create([
                'path' => $path,
                'is_primary' => ! $hasPrimary && $index === 0,
            ]);
        }
    }

    /**
     * Ghi các trường Claude rút ra từ trang Shopee; trường rỗng giữ giá trị cũ.
     *
     * @param  array{name: string, price: ?int, sizes: string, material: string, colors: string, notes: string, reviews: string}  $fields
     */
    public function applyImportedFields(array $fields): void
    {
        $details = $this->details ?? [];
        foreach (['sizes', 'material', 'colors', 'notes'] as $key) {
            if ($fields[$key] !== '') {
                $details[$key] = $fields[$key];
            }
        }

        $this->update([
            'name' => $fields['name'] ?: $this->name,
            'price' => $fields['price'] ?? $this->price,
            'details' => $details,
            'reviews' => $fields['reviews'] ?: $this->reviews,
        ]);
    }
}
