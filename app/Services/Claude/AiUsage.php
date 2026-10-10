<?php

namespace App\Services\Claude;

use App\Models\AiUsageLog;
use App\Models\AppSetting;
use App\Models\Product;
use App\Models\SampleVideo;
use App\Support\CreditPricing;
use App\Support\Credits;
use App\Support\ProjectCredits;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ghi chi phí mỗi lần gọi Claude vào ai_usage_logs.
 * Job đặt ngữ cảnh (người dùng, dự án, đối tượng) bằng withContext(); ClaudeCli và các driver API tự ghi.
 */
class AiUsage
{
    /** Giá mặc định (USD / 1 triệu token) theo dòng model; admin sửa được trên màn hình Chi phí AI. */
    public const DEFAULT_PRICES = [
        'opus' => ['input' => 5.0, 'output' => 25.0],
        'sonnet' => ['input' => 3.0, 'output' => 15.0],
        'haiku' => ['input' => 1.0, 'output' => 5.0],
    ];

    public const DEFAULT_USD_VND = 26000;

    /** Ghi cache tính 1.25 lần giá input, đọc cache 0.1 lần (prompt caching 5 phút). */
    private const CACHE_WRITE = 1.25;

    private const CACHE_READ = 0.1;

    /** @var array{user_id?: ?int, video_project_id?: ?int, subject?: ?string} */
    private static array $context = [];

    /**
     * @param  array{user_id?: ?int, video_project_id?: ?int, subject?: ?string}  $context
     */
    public static function withContext(array $context, Closure $callback): mixed
    {
        $previous = self::$context;
        self::$context = [...$previous, ...$context];

        try {
            return $callback();
        } finally {
            self::$context = $previous;
        }
    }

    /**
     * Gọi Claude API qua $call, ghi token và chi phí (cả khi lỗi). Ngoại lệ được ném lại nguyên vẹn.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    public static function api(string $action, string $model, Closure $call): mixed
    {
        $started = hrtime(true);
        try {
            $message = $call();
        } catch (Throwable $e) {
            self::record($action, 'claude_api', $model, [], null, self::elapsed($started), $e->getMessage());

            throw $e;
        }

        $usage = $message->usage ?? null;
        $tokens = [
            'input_tokens' => (int) ($usage->inputTokens ?? 0),
            'output_tokens' => (int) ($usage->outputTokens ?? 0),
            'cache_write_tokens' => (int) ($usage->cacheCreationInputTokens ?? 0),
            'cache_read_tokens' => (int) ($usage->cacheReadInputTokens ?? 0),
        ];
        $error = ($message->stopReason ?? null) === 'refusal' ? 'Claude từ chối yêu cầu.' : null;
        self::record($action, 'claude_api', $model, $tokens, null, self::elapsed($started), $error);

        return $message;
    }

    /**
     * Ghi một lần chạy Claude Code CLI từ sự kiện "result" (có thể null khi CLI không chạy được).
     * Gói Pro/Max không tính tiền theo token: total_cost_usd là chi phí ước tính theo giá API.
     */
    public static function cli(string $action, ?array $result, ?string $model, int $durationMs, ?string $error): void
    {
        $tokens = ['input_tokens' => 0, 'output_tokens' => 0, 'cache_write_tokens' => 0, 'cache_read_tokens' => 0];
        $modelUsage = is_array($result['modelUsage'] ?? null) ? $result['modelUsage'] : [];

        if ($modelUsage !== []) {
            // Có thể có nhiều model (CLI dùng model nhỏ cho việc phụ): cộng tất cả, model chính là model tốn nhất.
            foreach ($modelUsage as $usage) {
                $tokens['input_tokens'] += (int) ($usage['inputTokens'] ?? 0);
                $tokens['output_tokens'] += (int) ($usage['outputTokens'] ?? 0);
                $tokens['cache_write_tokens'] += (int) ($usage['cacheCreationInputTokens'] ?? 0);
                $tokens['cache_read_tokens'] += (int) ($usage['cacheReadInputTokens'] ?? 0);
            }
            uasort($modelUsage, fn ($a, $b) => ($b['costUSD'] ?? 0) <=> ($a['costUSD'] ?? 0));
            $model = (string) array_key_first($modelUsage);
        } elseif (is_array($result['usage'] ?? null)) {
            $usage = $result['usage'];
            $tokens = [
                'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
                'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
                'cache_write_tokens' => (int) ($usage['cache_creation_input_tokens'] ?? 0),
                'cache_read_tokens' => (int) ($usage['cache_read_input_tokens'] ?? 0),
            ];
        }

        $cost = isset($result['total_cost_usd']) ? (float) $result['total_cost_usd'] : null;
        self::record($action, 'claude_cli', $model, $tokens, $cost, (int) ($result['duration_ms'] ?? $durationMs), $error);
    }

    /** Chi phí (USD) theo bảng giá hiện tại. */
    public static function cost(?string $model, int $input, int $output, int $cacheWrite = 0, int $cacheRead = 0): float
    {
        $price = self::priceFor($model);

        return ($input * $price['input']
            + $cacheWrite * $price['input'] * self::CACHE_WRITE
            + $cacheRead * $price['input'] * self::CACHE_READ
            + $output * $price['output']) / 1_000_000;
    }

    /** @return array{input: float, output: float} */
    public static function priceFor(?string $model): array
    {
        $prices = self::settings()['prices'];
        $name = strtolower((string) $model);
        foreach (array_keys($prices) as $family) {
            if (str_contains($name, $family)) {
                return $prices[$family];
            }
        }

        return $prices['sonnet'] ?? self::DEFAULT_PRICES['sonnet'];
    }

    /** @return array{usd_vnd: int, prices: array<string, array{input: float, output: float}>} */
    public static function settings(): array
    {
        $saved = json_decode((string) AppSetting::get(AppSetting::AI_PRICING), true) ?: [];
        $prices = self::DEFAULT_PRICES;
        foreach ($prices as $family => $default) {
            $prices[$family] = [
                'input' => (float) ($saved['prices'][$family]['input'] ?? $default['input']),
                'output' => (float) ($saved['prices'][$family]['output'] ?? $default['output']),
            ];
        }

        return ['usd_vnd' => (int) ($saved['usd_vnd'] ?? self::DEFAULT_USD_VND), 'prices' => $prices];
    }

    public static function saveSettings(array $settings): void
    {
        AppSetting::put(AppSetting::AI_PRICING, json_encode($settings));
    }

    private static function record(string $action, string $provider, ?string $model, array $tokens, ?float $cost, int $durationMs, ?string $error): void
    {
        $tokens = [...['input_tokens' => 0, 'output_tokens' => 0, 'cache_write_tokens' => 0, 'cache_read_tokens' => 0], ...$tokens];

        // Ghi log lỗi không được làm hỏng việc chính.
        try {
            $log = AiUsageLog::create([
                'user_id' => self::$context['user_id'] ?? auth()->id(),
                'video_project_id' => self::$context['video_project_id'] ?? null,
                'subject' => self::$context['subject'] ?? null,
                'action' => $action,
                'provider' => $provider,
                'model' => $model ? mb_substr($model, 0, 100) : null,
                'success' => $error === null,
                ...$tokens,
                'cost_usd' => $cost ?? self::cost($model, ...array_values($tokens)),
                'duration_ms' => $durationMs,
                'error' => $error !== null ? mb_substr($error, 0, 500) : null,
            ]);

            // Trừ Credits cho lượt thành công (kiểm tra kết nối của admin không tính).
            if ($log->success && $log->action !== 'auth_test') {
                Credits::charge(
                    $log->user_id,
                    CreditPricing::forAiCall((float) $log->cost_usd, $log->model),
                    $log->action,
                    "ai:{$log->id}",
                    self::subjectName($log->subject) ?? ProjectCredits::LABELS[$log->action] ?? AiUsageLog::actionLabel($log->action),
                    $log->video_project_id,
                );
            }
        } catch (Throwable $e) {
            Log::warning('ai_usage.record_failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    /** Tên sản phẩm / video mẫu cho dòng trừ Credits ("product:5" → tên sản phẩm). */
    private static function subjectName(?string $subject): ?string
    {
        [$type, $id] = array_pad(explode(':', (string) $subject, 2), 2, null);

        return match ($type) {
            'product' => Product::find($id)?->name,
            'sample_video' => SampleVideo::find($id)?->name,
            default => null,
        };
    }

    private static function elapsed(int $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
