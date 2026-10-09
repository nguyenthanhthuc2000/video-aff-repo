<?php

namespace App\Services\ProductImport;

/** Chỉ lấy tên từ tiêu đề tab; dùng trong test. */
class FakeProductExtractor implements ProductExtractor
{
    public function extract(PageSnapshot $page): array
    {
        return ProductExtractPrompt::normalize(['name' => $page->titleName()]);
    }
}
