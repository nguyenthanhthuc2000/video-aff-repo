<?php

namespace App\Services\ProductImport;

/** Nội dung trang sản phẩm Shopee / TikTok Shop do bookmarklet gom từ trình duyệt của người dùng (trang đã render). */
class PageSnapshot
{
    public function __construct(
        public readonly string $url,
        public readonly string $title,
        public readonly string $text,
    ) {}

    /** Tên sản phẩm lấy từ tiêu đề tab, bỏ đuôi " | Shopee Việt Nam" / " - TikTok Shop Vietnam". */
    public function titleName(): string
    {
        return trim(preg_replace('/\s*[|\-–]\s*(Shopee|TikTok\s*Shop).*$/iu', '', $this->title) ?? $this->title);
    }
}
