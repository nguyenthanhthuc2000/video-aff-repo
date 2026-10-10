<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiUsageLog;
use App\Services\Claude\AiUsage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/** Chi phí gọi Claude: tổng, theo thao tác, theo ngày, theo dự án / người dùng, từng lần gọi; bảng giá. */
class AiCostController extends Controller
{
    private const PERIODS = ['today' => 0, '7d' => 6, '30d' => 29, '90d' => 89, 'all' => null];

    public function index(Request $request): Response
    {
        $period = array_key_exists((string) $request->query('period'), self::PERIODS) ? (string) $request->query('period') : '30d';
        $action = array_key_exists((string) $request->query('action'), AiUsageLog::ACTIONS) ? (string) $request->query('action') : null;
        $provider = in_array($request->query('provider'), ['claude_cli', 'claude_api'], true) ? (string) $request->query('provider') : null;
        $from = self::PERIODS[$period] === null ? null : now()->subDays(self::PERIODS[$period])->startOfDay();

        $base = fn (): Builder => AiUsageLog::query()
            ->when($from, fn ($query) => $query->where('created_at', '>=', $from))
            ->when($action, fn ($query) => $query->where('action', $action))
            ->when($provider, fn ($query) => $query->where('provider', $provider));

        $sums = 'count(*) as calls, sum(case when success = 1 then 0 else 1 end) as failed, sum(input_tokens) as input_tokens, '
            .'sum(output_tokens) as output_tokens, sum(cache_write_tokens) as cache_write_tokens, sum(cache_read_tokens) as cache_read_tokens, '
            .'sum(cost_usd) as cost_usd, avg(duration_ms) as avg_duration_ms';

        $totals = self::numbers($base()->selectRaw($sums)->first()?->getAttributes() ?? []);
        $projects = (int) $base()->whereNotNull('video_project_id')->distinct()->count('video_project_id');
        $projectCost = (float) $base()->whereNotNull('video_project_id')->sum('cost_usd');
        $totals['projects'] = $projects;
        $totals['cost_per_project'] = $projects > 0 ? $projectCost / $projects : 0;

        $byAction = $base()->selectRaw("action, {$sums}")->groupBy('action')->orderByDesc('cost_usd')->get()
            ->map(fn (AiUsageLog $row) => ['action' => $row->action, 'label' => AiUsageLog::actionLabel($row->action), ...self::numbers($row->getAttributes())])
            ->map(fn (array $row) => [...$row, 'avg_cost_usd' => $row['calls'] > 0 ? $row['cost_usd'] / $row['calls'] : 0]);

        $byModel = $base()->selectRaw("provider, model, {$sums}")->groupBy('provider', 'model')->orderByDesc('cost_usd')->get()
            ->map(fn (AiUsageLog $row) => ['provider' => $row->provider, 'model' => $row->model, ...self::numbers($row->getAttributes())]);

        // Biểu đồ theo ngày: tối đa 30 ngày gần nhất trong khoảng đang lọc, ngày không có lượt gọi là 0.
        $days = min(30, self::PERIODS[$period] ?? 29) + 1;
        $start = now()->subDays($days - 1)->startOfDay();
        $daily = $base()->where('created_at', '>=', $start)->selectRaw('date(created_at) as day, count(*) as calls, sum(cost_usd) as cost_usd')
            ->groupBy('day')->get()->keyBy(fn ($row) => Carbon::parse($row->day)->toDateString());
        $byDay = collect(range(0, $days - 1))->map(function (int $i) use ($start, $daily) {
            $day = $start->copy()->addDays($i)->toDateString();

            return ['day' => $day, 'calls' => (int) ($daily[$day]->calls ?? 0), 'cost_usd' => (float) ($daily[$day]->cost_usd ?? 0)];
        });

        $topProjects = $base()->with(['videoProject.product:id,name', 'videoProject.user:id,email'])->whereNotNull('video_project_id')
            ->selectRaw('video_project_id, count(*) as calls, sum(cost_usd) as cost_usd')
            ->groupBy('video_project_id')->orderByDesc('cost_usd')->limit(8)->get()
            ->map(fn (AiUsageLog $row) => [
                'id' => $row->video_project_id,
                'product' => $row->videoProject?->product?->name,
                'user' => $row->videoProject?->user?->email,
                'calls' => (int) $row->calls,
                'cost_usd' => (float) $row->cost_usd,
            ]);

        $topUsers = $base()->with('user:id,name,email')->whereNotNull('user_id')
            ->selectRaw('user_id, count(*) as calls, sum(cost_usd) as cost_usd')
            ->groupBy('user_id')->orderByDesc('cost_usd')->limit(8)->get()
            ->map(fn (AiUsageLog $row) => [
                'id' => $row->user_id,
                'name' => $row->user?->name,
                'email' => $row->user?->email,
                'calls' => (int) $row->calls,
                'cost_usd' => (float) $row->cost_usd,
            ]);

        $logs = $base()->with(['user:id,email', 'videoProject.product:id,name'])->latest('id')->paginate(20)->withQueryString()
            ->through(fn (AiUsageLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'label' => AiUsageLog::actionLabel($log->action),
                'provider' => $log->provider,
                'model' => $log->model,
                'success' => $log->success,
                'input_tokens' => $log->input_tokens,
                'output_tokens' => $log->output_tokens,
                'cache_write_tokens' => $log->cache_write_tokens,
                'cache_read_tokens' => $log->cache_read_tokens,
                'cost_usd' => (float) $log->cost_usd,
                'duration_ms' => $log->duration_ms,
                'error' => $log->error,
                'user' => $log->user?->email,
                'project' => $log->video_project_id ? ['id' => $log->video_project_id, 'product' => $log->videoProject?->product?->name] : null,
                'subject' => $log->subject,
                'created_at' => $log->created_at,
            ]);

        return Inertia::render('admin/ai-costs', [
            'filters' => ['period' => $period, 'action' => $action, 'provider' => $provider],
            'actions' => AiUsageLog::ACTIONS,
            'totals' => $totals,
            'byAction' => $byAction,
            'byModel' => $byModel,
            'byDay' => $byDay,
            'topProjects' => $topProjects,
            'topUsers' => $topUsers,
            'logs' => $logs,
            'pricing' => AiUsage::settings(),
        ]);
    }

    /** Bảng giá dùng cho lượt gọi Claude API (CLI tự báo chi phí) và tỉ giá đổi sang VNĐ. */
    public function updatePricing(Request $request): RedirectResponse
    {
        $rules = ['usd_vnd' => ['required', 'integer', 'min:1000', 'max:100000']];
        foreach (array_keys(AiUsage::DEFAULT_PRICES) as $family) {
            $rules["prices.{$family}.input"] = ['required', 'numeric', 'min:0', 'max:1000'];
            $rules["prices.{$family}.output"] = ['required', 'numeric', 'min:0', 'max:1000'];
        }
        $validated = $request->validate($rules, [], ['usd_vnd' => 'tỉ giá']);

        AiUsage::saveSettings($validated);

        return back()->with('status', 'Đã lưu bảng giá. Lượt gọi mới sẽ tính theo giá này.');
    }

    /** @return array<string, int|float> */
    private static function numbers(array $row): array
    {
        return [
            'calls' => (int) ($row['calls'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'input_tokens' => (int) ($row['input_tokens'] ?? 0),
            'output_tokens' => (int) ($row['output_tokens'] ?? 0),
            'cache_write_tokens' => (int) ($row['cache_write_tokens'] ?? 0),
            'cache_read_tokens' => (int) ($row['cache_read_tokens'] ?? 0),
            'cost_usd' => (float) ($row['cost_usd'] ?? 0),
            'avg_duration_ms' => (int) round((float) ($row['avg_duration_ms'] ?? 0)),
        ];
    }
}
