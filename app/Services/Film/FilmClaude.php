<?php

namespace App\Services\Film;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use App\Services\Claude\AiUsage;
use App\Services\Claude\ClaudeCli;
use App\Services\Claude\ClaudeCliException;
use App\Services\Script\ScriptWriterException;
use App\Support\ClaudeModels;
use App\Support\ScriptDriver;
use Closure;

/** Gọi Claude trả JSON theo schema cho các bước phim (CLI gói Pro/Max hoặc API); driver fake dùng mẫu; chế độ dán tay không hỗ trợ. */
final class FilmClaude
{
    /**
     * @param  string  $action  Thao tác (chọn model, dòng chi phí): film_plan, film_episode...
     * @param  Closure(): array  $sample  Kết quả mẫu cho driver fake (test).
     *
     * @throws ScriptWriterException
     */
    public static function json(string $system, string $text, array $schema, string $action, string $logContext, int $maxTokens, Closure $sample): array
    {
        $content = [['type' => 'text', 'text' => $text]];

        return match (ScriptDriver::current()) {
            'claude_cli' => self::viaCli($system, $content, $schema, $logContext),
            'claude' => self::viaApi($system, $content, $schema, $action, $maxTokens),
            'fake' => $sample(),
            // Chế độ dán tay claude.ai không hợp với nội dung dài nhiều phần.
            default => throw new ScriptWriterException('Phim hoạt hình cần kết nối Claude (Claude Code CLI hoặc API). Admin vào Cài đặt hệ thống → Claude để đăng nhập.'),
        };
    }

    private static function viaCli(string $system, array $content, array $schema, string $logContext): array
    {
        try {
            return app(ClaudeCli::class)->json($system, $content, $schema, $logContext);
        } catch (ClaudeCliException $e) {
            throw $e->retryable ? ScriptWriterException::retryable($e->getMessage(), $e) : new ScriptWriterException($e->getMessage(), 0, $e);
        }
    }

    private static function viaApi(string $system, array $content, array $schema, string $action, int $maxTokens): array
    {
        $model = ClaudeModels::for($action, config('videoaff.claude.model'));
        try {
            $message = AiUsage::api($action, $model, fn () => (new Client(apiKey: config('videoaff.claude.api_key')))->messages->create(
                maxTokens: $maxTokens,
                model: $model,
                system: [['type' => 'text', 'text' => $system]],
                messages: [['role' => 'user', 'content' => $content]],
                outputConfig: ['format' => ['type' => 'json_schema', 'schema' => $schema]],
            ));
        } catch (AnthropicException $e) {
            throw ScriptWriterException::retryable('Gọi Claude API thất bại: '.$e->getMessage(), $e);
        }
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                return (array) json_decode($block->text, true);
            }
        }

        throw new ScriptWriterException('Claude không trả về nội dung.');
    }
}
