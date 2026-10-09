<?php

namespace App\Services\ProductImport;

interface ProductExtractor
{
    /**
     * Đọc nội dung trang sản phẩm Shopee và rút ra các trường của form sản phẩm.
     *
     * @return array{name: string, price: ?int, sizes: string, material: string, colors: string, notes: string, reviews: string}
     *
     * @throws ProductImportException
     */
    public function extract(PageSnapshot $page): array;
}
