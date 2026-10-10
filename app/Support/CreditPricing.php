<?php

namespace App\Support;

use App\Models\AiUsageLog;
use App\Models\AppSetting;
use App\Services\Claude\AiUsage;

/**
 * Giá bán Credits (1 Credit = 1đ, nạp 10.000đ được 10.000 Credits).
 * - Bước dùng AI viết chữ (Claude): Credits = chi phí thật (USD → VNĐ) × (1 + % lãi của dòng model), tối thiểu MIN_AI_CALL mỗi lần gọi.
 *   Model rẻ có % lãi cao hơn vì số tiền tuyệt đối rất nhỏ; model đắt % lãi thấp hơn để giá video không bị đội quá.
 * - Bước tạo ảnh / clip / xuất video: giá cố định mỗi lần (muse.ai qua trình duyệt không tính phí theo lượt, nhưng tốn thời gian, hạn mức tài khoản và máy chủ).
 * Admin đổi ở Cài đặt hệ thống → Giá bán Credits.
 */
class CreditPricing
{
    public const VND_PER_CREDIT = 1;

    /** Đề xuất: % lãi trên chi phí API theo dòng model. */
    public const DEFAULT_MARKUP = ['opus' => 150, 'sonnet' => 200, 'haiku' => 300];

    /** Đề xuất: Credits tặng khi đăng ký (đủ khoảng 2–3 video ảnh tĩnh để dùng thử). */
    public const DEFAULT_SIGNUP_BONUS = 30000;

    /** Đề xuất: Credits tối thiểu mỗi lần gọi AI viết chữ (để lượt rất nhỏ vẫn có lãi). */
    public const DEFAULT_MIN_AI_CALL = 100;

    /** Đề xuất: giá cố định (Credits) mỗi lần cho các bước không tính theo token. */
    public const DEFAULT_STEPS = [
        'image' => 800,
        'clip_style' => 2500,
        'clip_copy' => 4000,
        'render' => 1000,
        'render_long' => 1500,
    ];

    public const STEP_LABELS = [
        'image' => 'Tạo một ảnh cảnh',
        'clip_style' => 'Một clip chuyển động có sẵn',
        'clip_copy' => 'Một clip sao chép chuyển động video mẫu',
        'render' => 'Xuất video đến 30 giây',
        'render_long' => 'Xuất video trên 30 giây',
    ];

    /** @return array{markup: array<string, int>, min_ai_call: int, steps: array<string, int>, signup_bonus: int} */
    public static function settings(): array
    {
        $saved = json_decode((string) AppSetting::get(AppSetting::CREDIT_PRICING), true) ?: [];

        return [
            'markup' => collect(self::DEFAULT_MARKUP)->map(fn ($default, $family) => (int) ($saved['markup'][$family] ?? $default))->all(),
            'min_ai_call' => (int) ($saved['min_ai_call'] ?? self::DEFAULT_MIN_AI_CALL),
            'steps' => collect(self::DEFAULT_STEPS)->map(fn ($default, $step) => (int) ($saved['steps'][$step] ?? $default))->all(),
            'signup_bonus' => (int) ($saved['signup_bonus'] ?? self::DEFAULT_SIGNUP_BONUS),
        ];
    }

    public static function save(array $settings): void
    {
        AppSetting::put(AppSetting::CREDIT_PRICING, json_encode($settings));
    }

    public static function family(?string $model): string
    {
        $name = strtolower((string) $model);
        foreach (array_keys(self::DEFAULT_MARKUP) as $family) {
            if (str_contains($name, $family)) {
                return $family;
            }
        }

        return 'sonnet';
    }

    /** Credits cho một lần gọi AI viết chữ có chi phí $costUsd. Lượt lỗi không tính. */
    public static function forAiCall(float $costUsd, ?string $model, ?array $settings = null, ?int $usdVnd = null): int
    {
        $settings ??= self::settings();
        $vnd = $costUsd * ($usdVnd ?? AiUsage::settings()['usd_vnd']);
        $credits = $vnd * (1 + $settings['markup'][self::family($model)] / 100) / self::VND_PER_CREDIT;

        // Làm tròn lên bội số 10 cho dễ đọc.
        return (int) max($settings['min_ai_call'], ceil($credits / 10) * 10);
    }

    /**
     * Credits ước tính cho các bước AI viết chữ (theo trung bình 100 lượt gần nhất, chưa có thì theo token mẫu với Sonnet).
     *
     * @return array{script: int, product_import: int, motion_analysis: int, scene_prompt_rewrite: int}
     */
    public static function aiEstimates(): array
    {
        $settings = self::settings();
        $usdVnd = AiUsage::settings()['usd_vnd'];
        $sample = ['script' => [5000, 2500], 'product_import' => [6000, 600], 'motion_analysis' => [15000, 1500], 'scene_prompt_rewrite' => [900, 250]];

        return collect($sample)->map(function (array $tokens, string $action) use ($settings, $usdVnd) {
            $recent = AiUsageLog::where('action', $action)->where('success', true)->latest('id')->limit(100)->get(['cost_usd', 'model']);
            if ($recent->isEmpty()) {
                return self::forAiCall(AiUsage::cost('claude-sonnet-5-5', ...$tokens), 'sonnet', $settings, $usdVnd);
            }

            return (int) round($recent->avg(fn (AiUsageLog $log) => self::forAiCall((float) $log->cost_usd, $log->model, $settings, $usdVnd)));
        })->all();
    }

    /** Bảng giá cho máy tính ước lượng (trang chủ, Nạp tiền). */
    public static function publicRates(): array
    {
        return [...self::settings()['steps'], ...self::aiEstimates()];
    }
}
