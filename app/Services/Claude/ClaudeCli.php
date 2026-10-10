<?php

namespace App\Services\Claude;

use App\Support\ClaudeModels;
use App\Support\ScriptDriver;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Gọi Claude Code CLI (`claude -p`) đăng nhập bằng gói Claude Pro/Max của người dùng, thay cho API key.
 * Token lấy bằng `claude setup-token` (CLAUDE_CODE_OAUTH_TOKEN). Mọi tool bị tắt: chỉ sinh chữ, không đọc file hay chạy lệnh.
 */
class ClaudeCli
{
    public function __construct(
        private readonly string $binary,
        private readonly ?string $oauthToken,
        private readonly ?string $model,
        private readonly int $timeout,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            config('videoaff.claude_cli.binary'),
            ScriptDriver::claudeOauthToken(),
            config('videoaff.claude_cli.model'),
            config('videoaff.claude_cli.timeout'),
        );
    }

    /**
     * @param  list<array>  $content  Content block theo định dạng Messages API (text, image base64).
     * @return array Kết quả đã kiểm tra theo $schema.
     *
     * @throws ClaudeCliException
     */
    public function json(string $system, array $content, array $schema, string $logContext): array
    {
        // Thao tác cho màn hình Chi phí AI: "script:12" thành "script".
        $action = strtok($logContext, ':') ?: $logContext;
        $started = hrtime(true);
        $final = null;
        $model = ClaudeModels::for($action, $this->model);

        try {
            $output = $this->run($system, $content, $schema, $model, $logContext, $final);
        } catch (ClaudeCliException $e) {
            AiUsage::cli($action, $final, $model, (int) ((hrtime(true) - $started) / 1_000_000), $e->getMessage());

            throw $e;
        }

        AiUsage::cli($action, $final, $model, (int) ((hrtime(true) - $started) / 1_000_000), null);

        return $output;
    }

    /** @param  array|null  $final  Nhận sự kiện "result" của CLI (để ghi chi phí cả khi lỗi). */
    private function run(string $system, array $content, array $schema, ?string $model, string $logContext, ?array &$final): array
    {
        $command = [
            $this->binary, '-p',
            '--system-prompt', $system,
            '--tools', '',
            '--setting-sources', '',
            '--strict-mcp-config',
            '--no-session-persistence',
            '--input-format', 'stream-json',
            '--output-format', 'stream-json',
            '--verbose',
            '--json-schema', json_encode($schema, JSON_UNESCAPED_UNICODE),
        ];
        if ($model) {
            $command = [...$command, '--model', $model];
        }

        $input = json_encode(['type' => 'user', 'message' => ['role' => 'user', 'content' => $content]], JSON_UNESCAPED_UNICODE)."\n";

        $process = Process::timeout($this->timeout)
            ->path(sys_get_temp_dir())
            ->env(array_filter(['CLAUDE_CODE_OAUTH_TOKEN' => $this->oauthToken]))
            ->input($input);

        try {
            $result = $process->run($command);
        } catch (ProcessTimedOutException $e) {
            throw new ClaudeCliException('Claude Code chạy quá thời gian cho phép.', retryable: true, previous: $e);
        }

        // Tách theo "\n" thật: preg_split('/\R/') không có cờ u coi byte 0x85 là xuống dòng, cắt đôi chữ "ễ" (E1 BB 85).
        foreach (explode("\n", $result->output()) as $line) {
            $event = json_decode(trim($line), true, flags: JSON_INVALID_UTF8_SUBSTITUTE);
            if (($event['type'] ?? null) === 'result') {
                $final = $event;
            }
        }

        if ($final === null) {
            $error = trim($result->errorOutput());
            if ($result->exitCode() === 127 || str_contains($error, 'not found') || str_contains($error, 'No such file')) {
                throw new ClaudeCliException('Không tìm thấy lệnh `claude` (Claude Code CLI) trên máy chủ.');
            }

            // Stdout là luồng sự kiện JSON (có cả nội dung sản phẩm): chỉ ghi log, không đưa vào thông báo lỗi.
            Log::warning('claude_cli: không thấy sự kiện result', [
                'context' => $logContext,
                'exit_code' => $result->exitCode(),
                'stdout_tail' => mb_substr($result->output(), -1000),
            ]);

            throw new ClaudeCliException(
                'Claude Code lỗi'.($error !== '' ? ': '.mb_substr($error, -300) : ' (mã thoát '.$result->exitCode().', xem log).'),
                retryable: true,
            );
        }

        Log::info('claude_cli', [
            'context' => $logContext,
            'subtype' => $final['subtype'] ?? null,
            'duration_ms' => $final['duration_ms'] ?? null,
            // Ước tính theo giá API; dùng gói Pro/Max thì không bị tính thêm tiền.
            'notional_cost_usd' => $final['total_cost_usd'] ?? null,
        ]);

        if (($final['is_error'] ?? false) || ! is_array($final['structured_output'] ?? null)) {
            throw self::errorFor((string) ($final['result'] ?? $final['subtype'] ?? 'không rõ'));
        }

        return $final['structured_output'];
    }

    private static function errorFor(string $message): ClaudeCliException
    {
        $lower = mb_strtolower($message);

        return match (true) {
            str_contains($lower, 'not logged in') || str_contains($lower, '/login') || str_contains($lower, 'oauth') || str_contains($lower, 'authentication') => new ClaudeCliException(
                'Claude Code chưa đăng nhập. Chạy `claude setup-token` và đặt CLAUDE_CODE_OAUTH_TOKEN trong .env.',
            ),
            str_contains($lower, 'limit') || str_contains($lower, 'overloaded') || str_contains($lower, 'rate') => new ClaudeCliException(
                'Gói Claude đang chạm giới hạn sử dụng, sẽ thử lại sau: '.mb_substr($message, 0, 200),
                retryable: true,
            ),
            default => new ClaudeCliException('Claude Code không trả kết quả: '.mb_substr($message, 0, 300)),
        };
    }

    /** Ảnh trên disk media thành content block (bỏ qua định dạng Claude không đọc được). */
    public static function imageBlock(?string $contents, ?string $mime): ?array
    {
        if ($contents === null || ! in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            return null;
        }

        return ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($contents)]];
    }
}
