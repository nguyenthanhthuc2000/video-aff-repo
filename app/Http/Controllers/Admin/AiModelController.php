<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiUsageLog;
use App\Services\Claude\AiUsage;
use App\Support\ClaudeModels;
use App\Support\ScriptDriver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Chọn model Claude cho từng thao tác, kèm ước tính chi phí mỗi lần / mỗi tháng theo từng model. */
class AiModelController extends Controller
{
    /** Token mẫu khi chưa có lượt gọi nào ghi token (ước lượng thô). */
    private const SAMPLE_TOKENS = [
        'script' => ['input' => 5000, 'output' => 2500],
        'product_import' => ['input' => 6000, 'output' => 600],
        'scene_prompt_rewrite' => ['input' => 900, 'output' => 250],
        'motion_analysis' => ['input' => 15000, 'output' => 1500],
        'topic_short' => ['input' => 1500, 'output' => 2500],
        'film_plan' => ['input' => 2500, 'output' => 6000],
        'film_episode' => ['input' => 3500, 'output' => 3000],
    ];

    public function show(): Response
    {
        $settings = ClaudeModels::settings();
        $since = now()->subDays(30);

        $actions = collect(ClaudeModels::ACTIONS)->map(function (array $meta, string $action) use ($since) {
            $tokens = AiUsageLog::query()->where('action', $action)->where('success', true)->where('input_tokens', '>', 0)
                ->latest('id')->limit(200)->get(['input_tokens', 'output_tokens', 'cache_write_tokens', 'cache_read_tokens', 'cost_usd']);
            $measured = $tokens->isNotEmpty();
            $avg = fn (string $column) => (int) round($tokens->avg($column) ?? 0);
            $sample = self::SAMPLE_TOKENS[$action];
            $usage = $measured
                ? ['input' => $avg('input_tokens'), 'cache_write' => $avg('cache_write_tokens'), 'cache_read' => $avg('cache_read_tokens'), 'output' => $avg('output_tokens')]
                : ['input' => $sample['input'], 'cache_write' => 0, 'cache_read' => 0, 'output' => $sample['output']];
            $monthCalls = AiUsageLog::where('action', $action)->where('created_at', '>=', $since)->count();

            return [
                'action' => $action,
                'label' => AiUsageLog::actionLabel($action),
                'hint' => $meta['hint'],
                'recommended' => $meta['recommended'],
                'last_model' => AiUsageLog::where('action', $action)->whereNotNull('model')->latest('id')->value('model'),
                'tokens' => $usage,
                'measured_calls' => $tokens->count(),
                'month_calls' => $monthCalls,
                'month_cost_usd' => (float) AiUsageLog::where('action', $action)->where('created_at', '>=', $since)->sum('cost_usd'),
                // Chi phí ước tính mỗi lần gọi nếu dùng từng model (cùng lượng token).
                'estimates' => collect(ClaudeModels::MODELS)->map(fn ($_, $model) => AiUsage::cost(
                    $model, $usage['input'], $usage['output'], $usage['cache_write'], $usage['cache_read'],
                ))->all(),
            ];
        })->values();

        return Inertia::render('admin/ai-models', [
            'settings' => $settings,
            'models' => collect(ClaudeModels::MODELS)->map(fn (array $meta, string $id) => [
                'id' => $id,
                ...$meta,
                'price' => AiUsage::priceFor($id),
            ])->values(),
            'actions' => $actions,
            'envModel' => ScriptDriver::current() === 'claude' ? config('videoaff.claude.model') : (config('videoaff.claude_cli.model') ?: null),
            'driver' => ScriptDriver::current(),
            'usdVnd' => AiUsage::settings()['usd_vnd'],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $model = ['nullable', Rule::in(array_keys(ClaudeModels::MODELS))];
        $rules = ['default' => $model];
        foreach (array_keys(ClaudeModels::ACTIONS) as $action) {
            $rules["actions.{$action}"] = $model;
        }
        $validated = $request->validate($rules);

        ClaudeModels::save($validated['default'] ?? null, $validated['actions'] ?? []);

        return back()->with('status', 'Đã lưu model AI. Lượt gọi tiếp theo sẽ dùng model mới.');
    }
}
