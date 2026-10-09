<?php

namespace App\Services\ProductImport;

use App\Services\Claude\ClaudeCli;
use App\Services\Claude\ClaudeCliException;

/** Phân tích trang Shopee bằng Claude Code CLI (gói Claude Pro/Max), không cần API key. */
class ClaudeCliProductExtractor implements ProductExtractor
{
    public function __construct(private readonly ClaudeCli $cli) {}

    public function extract(PageSnapshot $page): array
    {
        try {
            $fields = $this->cli->json(
                ProductExtractPrompt::SYSTEM,
                [['type' => 'text', 'text' => ProductExtractPrompt::userText($page)]],
                ProductExtractPrompt::schema(),
                'product_import',
            );
        } catch (ClaudeCliException $e) {
            throw new ProductImportException($e->getMessage(), 0, $e);
        }

        return ProductExtractPrompt::normalize($fields);
    }
}
