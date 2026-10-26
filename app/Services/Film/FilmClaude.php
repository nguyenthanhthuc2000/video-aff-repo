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
     * @param  list<array{data: string, media_type: string}>  $images  Ảnh gửi kèm (nội dung file), đặt trước phần chữ.
     * @param  bool  $web  Cho Claude tìm và đọc web (research tư liệu): CLI bật WebSearch / WebFetch, API dùng server tool web search / web fetch.
     *
     * @throws ScriptWriterException
     */
    public static function json(string $system, string $text, array $schema, string $action, string $logContext, int $maxTokens, Closure $sample, array $images = [], bool $web = false): array
    {
        $content = [
            ...array_map(fn ($image) => ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $image['media_type'], 'data' => base64_encode($image['data'])]], $images),
            ['type' => 'text', 'text' => $text],
        ];

        return match (ScriptDriver::current()) {
            'claude_cli' => self::viaCli($system, $content, $schema, $logContext, $web ? ClaudeCli::WEB_TOOLS : []),
            'claude' => $web ? self::viaApiWeb($system, $content, $schema, $action, $maxTokens) : self::viaApi($system, $content, $schema, $action, $maxTokens),
            'fake' => $sample(),
            // Chế độ dán tay claude.ai không hợp với nội dung dài nhiều phần.
            default => throw new ScriptWriterException('Phim hoạt hình cần kết nối Claude (Claude Code CLI hoặc API). Admin vào Cài đặt hệ thống → Claude để đăng nhập.'),
        };
    }

    private static function viaCli(string $system, array $content, array $schema, string $logContext, array $tools = []): array
    {
        try {
            return app(ClaudeCli::class)->json($system, $content, $schema, $logContext, $tools);
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

    /**
     * API: lượt 1 Claude tìm / đọc web (server tool, trả chữ có nguồn), lượt 2 chuyển kết quả thành JSON theo schema
     * (trích nguồn web không dùng chung được với output_config.format).
     *
     * @throws ScriptWriterException
     */
    private static function viaApiWeb(string $system, array $content, array $schema, string $action, int $maxTokens): array
    {
        $model = ClaudeModels::for($action, config('videoaff.claude.model'));
        // Haiku chỉ có bản cơ bản; Sonnet / Opus dùng bản lọc động.
        $basic = str_contains($model, 'haiku');
        $tools = [
            ['type' => $basic ? 'web_search_20250305' : 'web_search_20260209', 'name' => 'web_search', 'max_uses' => 8],
            ['type' => $basic ? 'web_fetch_20250910' : 'web_fetch_20260209', 'name' => 'web_fetch', 'max_uses' => 6],
        ];
        $client = new Client(apiKey: config('videoaff.claude.api_key'));
        $messages = [['role' => 'user', 'content' => [...$content, ['type' => 'text', 'text' => 'Research first with web search / web fetch, then write your findings as plain text with the source URL after every item.']]]];
        $notes = '';
        try {
            // pause_turn: lượt tìm dài bị tạm dừng, gửi lại để Claude làm tiếp (tối đa 3 lần).
            for ($round = 0; $round < 4; $round++) {
                $message = AiUsage::api($action, $model, fn () => $client->messages->create(
                    maxTokens: $maxTokens,
                    model: $model,
                    system: [['type' => 'text', 'text' => $system]],
                    messages: $messages,
                    tools: $tools,
                ));
                foreach ($message->content as $block) {
                    if ($block->type === 'text') {
                        $notes .= $block->text;
                    }
                }
                $stop = $message->stopReason;
                if (($stop instanceof \BackedEnum ? $stop->value : $stop) !== 'pause_turn') {
                    break;
                }
                $messages[] = ['role' => 'assistant', 'content' => array_map(fn ($block) => $block->jsonSerialize(), $message->content)];
            }
        } catch (AnthropicException $e) {
            throw ScriptWriterException::retryable('Gọi Claude API (tìm web) thất bại: '.$e->getMessage(), $e);
        }
        if (trim($notes) === '') {
            throw ScriptWriterException::retryable('Claude không trả kết quả tìm web.');
        }

        return self::viaApi($system, [['type' => 'text', 'text' => "Research notes (already gathered from the web, with sources):\n{$notes}\n\nConvert these notes into the required JSON. Keep every source URL exactly as written; never add facts that are not in the notes."]], $schema, $action, $maxTokens);
    }
}
