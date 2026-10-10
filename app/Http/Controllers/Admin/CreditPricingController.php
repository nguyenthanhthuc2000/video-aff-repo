<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiUsageLog;
use App\Services\Claude\AiUsage;
use App\Support\ClaudeModels;
use App\Support\CreditPricing;
use App\Support\ScriptDriver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Giá bán Credits: % lãi theo dòng model AI, giá cố định các bước; xem trước lãi mỗi bước và mỗi video. */
class CreditPricingController extends Controller
{
    /** Token mẫu khi chưa có số liệu (giống trang Model AI). */
    private const SAMPLE_TOKENS = [
        'script' => [5000, 2500],
        'product_import' => [6000, 600],
        'scene_prompt_rewrite' => [900, 250],
        'motion_analysis' => [15000, 1500],
    ];

    public function show(): Response
    {
        $driverModel = ScriptDriver::current() === 'claude' ? config('videoaff.claude.model') : (config('videoaff.claude_cli.model') ?: null);

        $actions = collect(self::SAMPLE_TOKENS)->map(function (array $sample, string $action) use ($driverModel) {
            $recent = AiUsageLog::where('action', $action)->where('success', true)->where('input_tokens', '>', 0)->latest('id')->limit(100)
                ->get(['input_tokens', 'output_tokens', 'cache_write_tokens', 'cache_read_tokens']);
            $model = ClaudeModels::for($action, $driverModel) ?? 'claude-sonnet-5-5';

            return [
                'action' => $action,
                'label' => AiUsageLog::actionLabel($action),
                'model' => $model,
                'family' => CreditPricing::family($model),
                'measured' => $recent->count(),
                'input_tokens' => $recent->isEmpty() ? $sample[0] : (int) round($recent->avg(fn ($log) => $log->input_tokens + $log->cache_write_tokens + $log->cache_read_tokens)),
                'output_tokens' => $recent->isEmpty() ? $sample[1] : (int) round($recent->avg('output_tokens')),
            ];
        })->values();

        return Inertia::render('admin/credit-pricing', [
            'settings' => CreditPricing::settings(),
            'defaults' => [
                'markup' => CreditPricing::DEFAULT_MARKUP,
                'min_ai_call' => CreditPricing::DEFAULT_MIN_AI_CALL,
                'steps' => CreditPricing::DEFAULT_STEPS,
                'signup_bonus' => CreditPricing::DEFAULT_SIGNUP_BONUS,
            ],
            'stepLabels' => CreditPricing::STEP_LABELS,
            'families' => collect(CreditPricing::DEFAULT_MARKUP)->map(fn ($_, $family) => [
                'family' => $family,
                'name' => collect(ClaudeModels::MODELS)->firstWhere('family', $family)['name'] ?? ucfirst($family),
                'price' => AiUsage::priceFor($family),
            ])->values(),
            'actions' => $actions,
            'usdVnd' => AiUsage::settings()['usd_vnd'],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = [
            'min_ai_call' => ['required', 'integer', 'min:0', 'max:100000'],
            'signup_bonus' => ['required', 'integer', 'min:0', 'max:10000000'],
        ];
        foreach (array_keys(CreditPricing::DEFAULT_MARKUP) as $family) {
            $rules["markup.{$family}"] = ['required', 'integer', 'min:0', 'max:2000'];
        }
        foreach (array_keys(CreditPricing::DEFAULT_STEPS) as $step) {
            $rules["steps.{$step}"] = ['required', 'integer', 'min:0', 'max:1000000'];
        }

        CreditPricing::save($request->validate($rules, [], ['min_ai_call' => 'tối thiểu mỗi lần gọi', 'signup_bonus' => 'Credits tặng khi đăng ký']));

        return back()->with('status', 'Đã lưu giá bán Credits.');
    }
}
