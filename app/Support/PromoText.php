<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductCategory;

/**
 * Video không nhắc ưu đãi / khuyến mãi / giảm giá (giá và ưu đãi trên Shopee thay đổi liên tục).
 * Lọc các cụm này khỏi chữ Claude viết, và chọn câu kêu gọi cuối video theo loại sản phẩm.
 */
final class PromoText
{
    private const WORDS = 'ưu đãi|khuyến mãi|khuyến mại|giảm giá|mã giảm giá|voucher|deal|flash sale|sale';

    /** "Xem màu, size, ưu đãi trong giỏ hàng" → "Xem màu, size trong giỏ hàng". */
    public static function strip(string $text): string
    {
        $words = self::WORDS;
        // Cụm nối sau dấu phẩy / "và" / "cùng": ", ưu đãi", " và các ưu đãi".
        $text = preg_replace("/\\s*(?:,|\\s(?:và|cùng|hoặc))\\s+(?:các\\s+|nhiều\\s+)?(?:{$words})(?=[\\s.,!?…]|$)/iu", '', $text) ?? $text;
        // Cụm đứng trước: "ưu đãi, màu, size" → "màu, size".
        $text = preg_replace("/(?<=^|\\s)(?:các\\s+)?(?:{$words})\\s*(?:,|\\s(?:và|cùng))\\s*/iu", '', $text) ?? $text;

        return trim(preg_replace('/\s{2,}/u', ' ', $text) ?? $text);
    }

    /** @param  list<string>  $hashtags */
    public static function stripHashtags(array $hashtags): array
    {
        return array_values(array_filter(
            $hashtags,
            fn (string $tag) => ! preg_match('/(sale|uudai|ưuđãi|khuyenmai|khuyếnmãi|giamgia|giảmgiá|voucher|deal)/iu', $tag),
        ));
    }

    /** Câu cuối video: có màu/size với quần áo, giày; sản phẩm khác thì "xem chi tiết". */
    public static function callToAction(Product $product): string
    {
        $key = ProductCategory::detect($product)?->key ?? '';

        return str_starts_with($key, 'clothing') || $key === 'shoes'
            ? 'Xem màu, size trong giỏ hàng'
            : 'Xem chi tiết trong giỏ hàng';
    }
}
