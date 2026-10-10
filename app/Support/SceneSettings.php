<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductCategory;

/** Bối cảnh quay gợi ý theo loại sản phẩm (master data product_categories), để các cảnh hợp với cách người mua dùng. */
final class SceneSettings
{
    /** Dự phòng khi bảng loại sản phẩm trống. */
    private const FALLBACK = ['đi dạo phố', 'ngồi quán cà phê', 'đi làm ở văn phòng', 'công viên cuối tuần', 'studio nền trơn cận sản phẩm'];

    /** @return list<string> */
    public static function suggestFor(Product $product): array
    {
        return ProductCategory::detect($product)?->sceneSettings() ?: self::FALLBACK;
    }
}
