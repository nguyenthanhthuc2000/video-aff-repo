<?php

namespace App\Services\ProductImport;

/** Nội dung trang Shopee do bookmarklet gom từ trình duyệt của người dùng (trang đã render). */
class PageSnapshot
{
    public function __construct(
        public readonly string $url,
        public readonly string $title,
        public readonly string $text,
    ) {}

    /** Tên sản phẩm lấy từ tiêu đề tab, bỏ đuôi " | Shopee Việt Nam". */
    public function titleName(): string
    {
        return trim(preg_replace('/\s*[|\-–]\s*Shopee.*$/iu', '', $this->title) ?? $this->title);
    }
}
