<?php

namespace App\Services\ProductImport;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Illuminate\Support\Facades\Log;

class ClaudeProductExtractor implements ProductExtractor
{
    public function __construct(
        private readonly Client $client,
        private readonly string $model,
    ) {}

    public function extract(PageSnapshot $page): array
    {
        try {
            $message = $this->client->messages->create(
                maxTokens: 4000,
                model: $this->model,
                system: [[
                    'type' => 'text',
                    'text' => ProductExtractPrompt::SYSTEM,
                    'cacheControl' => ['type' => 'ephemeral'],
                ]],
                messages: [['role' => 'user', 'content' => ProductExtractPrompt::userText($page)]],
                outputConfig: [
                    'effort' => 'low',
                    'format' => ['type' => 'json_schema', 'schema' => ProductExtractPrompt::schema()],
                ],
            );
        } catch (APIConnectionException $e) {
            throw new ProductImportException('Không kết nối được Claude API.', 0, $e);
        } catch (APIStatusException $e) {
            throw new ProductImportException(match (true) {
                $e->status === 401 => 'Khoá Claude API không hợp lệ (kiểm tra ANTHROPIC_API_KEY).',
                $e->status === 429 => 'Claude API đang giới hạn lượt gọi, thử lại sau.',
                $e->status >= 500 => 'Claude API đang lỗi tạm thời, thử lại sau.',
                default => "Claude API từ chối yêu cầu (HTTP {$e->status}).",
            }, 0, $e);
        } catch (AnthropicException $e) {
            throw new ProductImportException('Gọi Claude API thất bại: '.$e->getMessage(), 0, $e);
        }

        Log::info('claude.product_import', [
            'url' => $page->url,
            'model' => $this->model,
            'stop_reason' => $message->stopReason,
            'input_tokens' => $message->usage->inputTokens ?? null,
            'output_tokens' => $message->usage->outputTokens ?? null,
        ]);

        if ($message->stopReason === 'refusal') {
            throw new ProductImportException('Claude từ chối đọc trang này.');
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                return ProductExtractPrompt::normalize(json_decode($block->text, true));
            }
        }

        throw new ProductImportException('Claude không trả về thông tin sản phẩm.');
    }
}
