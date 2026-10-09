<?php

namespace App\Services\Script;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use App\Models\VideoProject;
use App\Support\Media;
use Illuminate\Support\Facades\Log;

class ClaudeScriptWriter implements ScriptWriter
{
    public function __construct(
        private readonly Client $client,
        private readonly string $model,
        private readonly int $maxTokens,
    ) {}

    public function write(VideoProject $project): array
    {
        try {
            $message = $this->client->messages->create(
                maxTokens: $this->maxTokens,
                model: $this->model,
                system: [[
                    'type' => 'text',
                    'text' => ScriptPrompt::SYSTEM,
                    'cacheControl' => ['type' => 'ephemeral'],
                ]],
                messages: [['role' => 'user', 'content' => $this->userContent($project)]],
                outputConfig: [
                    'effort' => 'medium',
                    'format' => ['type' => 'json_schema', 'schema' => ScriptSchema::schema()],
                ],
            );
        } catch (APIConnectionException $e) {
            throw ScriptWriterException::retryable('Không kết nối được Claude API.', $e);
        } catch (APIStatusException $e) {
            $retryable = $e->status === 429 || $e->status >= 500;
            $message = match (true) {
                $e->status === 401 => 'Khoá Claude API không hợp lệ (kiểm tra ANTHROPIC_API_KEY).',
                $e->status === 429 => 'Claude API đang giới hạn lượt gọi, thử lại sau.',
                $retryable => 'Claude API đang lỗi tạm thời, thử lại sau.',
                default => "Claude API từ chối yêu cầu (HTTP {$e->status}).",
            };

            throw $retryable ? ScriptWriterException::retryable($message, $e) : new ScriptWriterException($message, 0, $e);
        } catch (AnthropicException $e) {
            throw new ScriptWriterException('Gọi Claude API thất bại: '.$e->getMessage(), 0, $e);
        }

        Log::info('claude.script', [
            'project_id' => $project->id,
            'model' => $this->model,
            'stop_reason' => $message->stopReason,
            'input_tokens' => $message->usage->inputTokens ?? null,
            'output_tokens' => $message->usage->outputTokens ?? null,
        ]);

        if ($message->stopReason === 'refusal') {
            throw new ScriptWriterException('Claude từ chối viết kịch bản cho sản phẩm này.');
        }

        if ($message->stopReason === 'max_tokens') {
            throw new ScriptWriterException('Kịch bản bị cắt giữa chừng (vượt giới hạn token).');
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                return ScriptSchema::normalize(json_decode($block->text, true));
            }
        }

        throw new ScriptWriterException('Claude không trả về nội dung kịch bản.');
    }

    private function userContent(VideoProject $project): array
    {
        $product = $project->product;
        $text = ScriptPrompt::productInfo($product)."\nẢnh đính kèm là ảnh sản phẩm. Viết kịch bản theo đúng định dạng.";

        $content = [];
        foreach ($product->images()->orderByDesc('is_primary')->limit(3)->get() as $image) {
            $mime = Media::disk()->mimeType($image->path);
            $data = Media::disk()->get($image->path);
            if ($data === null || ! in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
                continue;
            }

            $content[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'mediaType' => $mime,
                    'data' => base64_encode($data),
                ],
            ];
        }
        $content[] = ['type' => 'text', 'text' => $text];

        return $content;
    }
}
