<?php

namespace App\Models;

use App\Enums\MotionStyle;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Master data loại sản phẩm: từ khoá nhận diện theo tên, bối cảnh quay và kiểu chuyển động gợi ý. */
class ProductCategory extends Model
{
    public const OTHER = 'other';

    protected $fillable = ['parent_id', 'key', 'name', 'keywords', 'scene_settings', 'motion_styles', 'usage', 'sort_order'];

    /** Dự phòng khi loại chưa có "usage". */
    public const DEFAULT_USAGE = 'wearing or holding the exact product from the product photos, the product clearly visible';

    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'scene_settings' => 'array',
            'motion_styles' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Loại của sản phẩm theo tên. Loại chính = từ khoá xuất hiện sớm nhất (tên Shopee thường mở đầu bằng loại:
     * "Đầm…", "Mũ…", "Ốp lưng…"), so khớp giữ dấu ("kèm" khác "kem"). Sau đó tìm loại phụ có từ khoá trong tên.
     */
    public static function detect(Product $product): ?self
    {
        /** @var Collection<int, self> $categories */
        $categories = self::query()->orderBy('sort_order')->orderBy('id')->get();
        $name = ' '.trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower((string) $product->name)) ?? '').' ';
        $has = fn (self $category, string $keyword) => mb_strpos($name, ' '.mb_strtolower($keyword).' ');

        $best = null;
        $bestPosition = PHP_INT_MAX;
        foreach ($categories->whereNull('parent_id') as $category) {
            foreach ($category->keywords ?? [] as $keyword) {
                $position = $has($category, $keyword);
                if ($position !== false && $position < $bestPosition) {
                    [$best, $bestPosition] = [$category, $position];
                }
            }
        }
        $best ??= $categories->firstWhere('key', self::OTHER);
        if ($best === null) {
            return null;
        }

        $child = $categories->where('parent_id', $best->id)->first(
            fn (self $child) => collect($child->keywords ?? [])->contains(fn ($keyword) => $has($child, $keyword) !== false),
        );
        $child?->setRelation('parent', $best);

        return $child ?? $best;
    }

    /** @return list<string> Bối cảnh gợi ý (loại phụ thiếu thì lấy của loại chính). */
    public function sceneSettings(): array
    {
        return array_values($this->scene_settings ?: ($this->parent?->scene_settings ?? []));
    }

    /** @return list<MotionStyle> Kiểu chuyển động gợi ý, từ hợp nhất (loại phụ thiếu thì lấy của loại chính). */
    public function motionStyles(): array
    {
        $values = $this->motion_styles ?: ($this->parent?->motion_styles ?? []);

        return array_values(array_filter(array_map(fn ($value) => MotionStyle::tryFrom((string) $value), $values)));
    }

    /** Cách sản phẩm xuất hiện trên người mẫu (tiếng Anh, cho prompt); loại phụ thiếu thì lấy của loại chính. */
    public function usage(): string
    {
        return $this->usage ?: ($this->parent?->usage ?: self::DEFAULT_USAGE);
    }

    /** Câu tả sản phẩm cho prompt ảnh / clip: tên sản phẩm và cách người mẫu đeo / cầm. */
    public static function productLine(Product $product): string
    {
        $name = mb_substr(trim((string) $product->name), 0, 120);
        $usage = self::detect($product)?->usage() ?? self::DEFAULT_USAGE;

        return "The product is \"{$name}\". The person is {$usage}.";
    }
}
