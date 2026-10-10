<?php

namespace App\Support;

use App\Models\AiUsageLog;
use App\Models\CreditTransaction;
use App\Models\VideoProject;
use App\Services\Claude\AiUsage;

/**
 * Lãi lỗ một video cho admin: chi phí vốn (VNĐ, USD) và giá bán (Credits = VNĐ) từng bước.
 * - Vốn: chi phí AI viết chữ (theo token, gói Pro/Max là ước tính theo giá API); ảnh / clip / xuất video qua muse.ai, ffmpeg không tốn tiền theo lượt.
 * - Giá bán: số Credits đã trừ thật; bước dùng chung (đọc sản phẩm, phân tích video mẫu) chia đều cho các video dùng chung.
 *   Video làm trước khi bật trừ Credits thì là giá ước tính theo bảng giá bán.
 */
class ProjectProfit
{
    private const ORDER = ['product_import', 'motion_analysis', 'script', 'scene_prompt_rewrite', 'images', 'clips', 'renders'];

    private const CHARGE_STEPS = ['image' => 'images', 'clip' => 'clips', 'render' => 'renders'];

    private const NO_COST_NOTE = 'muse.ai / máy chủ: không tốn tiền theo lượt';

    public static function for(VideoProject $project): array
    {
        $usdVnd = AiUsage::settings()['usd_vnd'];
        $cost = ProjectCost::forProjects(collect([$project]))[$project->id];
        $credits = ProjectCredits::forProjects(collect([$project]))[$project->id];

        $rows = [];
        $init = function (string $key) use (&$rows): void {
            $rows[$key] ??= [
                'key' => $key,
                'label' => ProjectCredits::LABELS[$key] ?? $key,
                'count' => 0,
                'cost_usd' => 0.0,
                'cost_vnd' => 0,
                'sale_credits' => 0,
                'shared_with' => null,
                'note' => null,
            ];
        };

        // Vốn: chi phí AI theo bước (đã chia bước dùng chung trong ProjectCost).
        foreach ($cost['steps'] as $step) {
            $key = $step['key'];
            $init($key);
            $rows[$key]['count'] = max($rows[$key]['count'], $step['calls'] - $step['failed']);
            $rows[$key]['cost_usd'] += $step['cost_usd'];
            $rows[$key]['shared_with'] = $step['shared_with'];
            if ($key === 'images') {
                $rows[$key]['note'] = self::NO_COST_NOTE;
            }
        }

        // Giá bán: đã trừ thật hoặc ước tính.
        foreach ($credits['steps'] as $step) {
            $key = $step['key'];
            $init($key);
            $rows[$key]['sale_credits'] += $step['credits'];
            $rows[$key]['count'] = max($rows[$key]['count'], $step['count']);
        }

        // Bước dùng chung bị trừ theo sản phẩm / video mẫu (không gắn dự án): chia cho số video dùng chung.
        if (! $credits['estimated']) {
            foreach (self::sharedCharges($project) as $key => [$total, $sharedWith]) {
                $init($key);
                $rows[$key]['sale_credits'] += (int) round($total / $sharedWith);
                $rows[$key]['shared_with'] = $sharedWith > 1 ? $sharedWith : null;
            }
        }

        foreach (['clips', 'renders'] as $key) {
            if (isset($rows[$key])) {
                $rows[$key]['note'] = self::NO_COST_NOTE;
            }
        }

        $rows = collect($rows)->sortBy(fn ($r) => array_search($r['key'], self::ORDER, true))->values()->map(function (array $r) use ($usdVnd) {
            $r['cost_vnd'] = (int) round($r['cost_usd'] * $usdVnd);
            $r['sale_vnd'] = $r['sale_credits'] * CreditPricing::VND_PER_CREDIT;
            $r['profit_vnd'] = $r['sale_vnd'] - $r['cost_vnd'];
            $r['margin'] = $r['sale_vnd'] > 0 ? round($r['profit_vnd'] / $r['sale_vnd'] * 100, 1) : null;

            return $r;
        })->all();

        $costVnd = array_sum(array_column($rows, 'cost_vnd'));
        $saleVnd = array_sum(array_column($rows, 'sale_vnd'));

        return [
            'estimated' => $credits['estimated'],
            'usd_vnd' => $usdVnd,
            'rows' => $rows,
            'totals' => [
                'cost_usd' => array_sum(array_column($rows, 'cost_usd')),
                'cost_vnd' => $costVnd,
                'sale_credits' => array_sum(array_column($rows, 'sale_credits')),
                'sale_vnd' => $saleVnd,
                'profit_vnd' => $saleVnd - $costVnd,
                'margin' => $saleVnd > 0 ? round(($saleVnd - $costVnd) / $saleVnd * 100, 1) : null,
            ],
        ];
    }

    /** @return array<string, array{0: int, 1: int}> bước => [Credits đã trừ, số video dùng chung] */
    private static function sharedCharges(VideoProject $project): array
    {
        $sources = [
            "product:{$project->product_id}" => fn () => VideoProject::where('product_id', $project->product_id)->count(),
        ];
        if ($project->sample_video_id) {
            $sources["sample_video:{$project->sample_video_id}"] = fn () => VideoProject::where('sample_video_id', $project->sample_video_id)->count();
        }

        $result = [];
        foreach ($sources as $subject => $count) {
            $logs = AiUsageLog::where('subject', $subject)->where('success', true)->get(['id', 'action']);
            if ($logs->isEmpty()) {
                continue;
            }
            $charged = CreditTransaction::whereIn('reference', $logs->map(fn ($log) => "ai:{$log->id}"))->whereIn('type', ['usage', 'refund'])
                ->get(['action', 'credits']);
            foreach ($charged->groupBy('action') as $action => $rows) {
                $key = self::CHARGE_STEPS[$action] ?? $action;
                $result[$key] = [(int) -$rows->sum('credits'), max(1, $count())];
            }
        }

        return $result;
    }
}
