<?php

namespace App\Support;

use App\Models\AiUsageLog;
use App\Models\SceneImage;
use App\Models\VideoProject;
use Illuminate\Support\Collection;

/**
 * Chi phí xử lý từng video, tách theo bước (chỉ admin xem).
 * Bước dùng chung (đọc trang sản phẩm, phân tích video mẫu) được chia đều cho các video dùng chung.
 */
class ProjectCost
{
    /** Thứ tự bước theo luồng làm video. */
    private const ORDER = ['product_import', 'motion_analysis', 'script', 'scene_prompt_rewrite', 'images'];

    /**
     * @param  Collection<int, VideoProject>  $projects
     * @return array<int, array{total_usd: float, calls: int, steps: list<array{key: string, label: string, calls: int, failed: int, cost_usd: float, input_tokens: int, output_tokens: int, avg_duration_ms: int, shared_with: int|null, note: string|null}>}>
     */
    public static function forProjects(Collection $projects): array
    {
        if ($projects->isEmpty()) {
            return [];
        }

        $ids = $projects->pluck('id');
        $sums = 'action, count(*) as calls, sum(case when success = 1 then 0 else 1 end) as failed, '
            .'sum(input_tokens + cache_write_tokens + cache_read_tokens) as input_tokens, sum(output_tokens) as output_tokens, sum(cost_usd) as cost_usd, avg(duration_ms) as avg_duration_ms';

        $own = AiUsageLog::query()->whereIn('video_project_id', $ids)
            ->selectRaw("video_project_id, {$sums}")->groupBy('video_project_id', 'action')->get()->groupBy('video_project_id');

        // Bước dùng chung: gắn theo sản phẩm / video mẫu, chia cho số video dùng chung.
        $productSubjects = $projects->pluck('product_id')->unique()->map(fn ($id) => "product:{$id}");
        $sampleSubjects = $projects->pluck('sample_video_id')->filter()->unique()->map(fn ($id) => "sample_video:{$id}");
        $shared = AiUsageLog::query()->whereIn('subject', $productSubjects->merge($sampleSubjects))
            ->selectRaw("subject, {$sums}")->groupBy('subject', 'action')->get()->keyBy(fn ($row) => "{$row->subject}|{$row->action}");
        $perProduct = VideoProject::query()->whereIn('product_id', $projects->pluck('product_id')->unique())
            ->selectRaw('product_id, count(*) as total')->groupBy('product_id')->pluck('total', 'product_id');
        $perSample = VideoProject::query()->whereIn('sample_video_id', $projects->pluck('sample_video_id')->filter()->unique())
            ->selectRaw('sample_video_id, count(*) as total')->groupBy('sample_video_id')->pluck('total', 'sample_video_id');

        $images = SceneImage::query()->join('scenes', 'scenes.id', '=', 'scene_images.scene_id')
            ->whereIn('scenes.video_project_id', $ids)
            ->selectRaw("scenes.video_project_id, count(*) as calls, sum(case when scene_images.status = 'failed' then 1 else 0 end) as failed, sum(scene_images.cost_usd) as cost_usd")
            ->groupBy('scenes.video_project_id')->get()->keyBy('video_project_id');

        $result = [];
        foreach ($projects as $project) {
            $steps = [];
            foreach ($own[$project->id] ?? [] as $row) {
                $steps[$row->action] = self::step($row->action, $row, 1);
            }

            $sharedRows = [
                "product:{$project->product_id}|product_import" => max(1, (int) ($perProduct[$project->product_id] ?? 1)),
                "sample_video:{$project->sample_video_id}|motion_analysis" => max(1, (int) ($perSample[$project->sample_video_id] ?? 1)),
            ];
            foreach ($sharedRows as $key => $count) {
                if ($row = $shared[$key] ?? null) {
                    $step = self::step($row->action, $row, $count);
                    // Cùng thao tác vừa gắn trực tiếp vừa dùng chung: cộng lại.
                    $steps[$row->action] = isset($steps[$row->action]) ? self::merge($steps[$row->action], $step) : $step;
                }
            }

            if ($row = $images[$project->id] ?? null) {
                $steps['images'] = [
                    'key' => 'images',
                    'label' => 'Tạo ảnh phân cảnh',
                    'calls' => (int) $row->calls,
                    'failed' => (int) $row->failed,
                    'cost_usd' => (float) $row->cost_usd,
                    'input_tokens' => 0,
                    'output_tokens' => 0,
                    'avg_duration_ms' => 0,
                    'shared_with' => null,
                    'note' => (float) $row->cost_usd > 0 ? null : 'Tạo bằng muse.ai qua trình duyệt, không tính phí theo lượt.',
                ];
            }

            $rank = fn (string $key) => ($index = array_search($key, self::ORDER, true)) === false ? 99 : $index;
            uksort($steps, fn ($a, $b) => $rank($a) <=> $rank($b));
            $steps = array_values($steps);

            $result[$project->id] = [
                'total_usd' => array_sum(array_column($steps, 'cost_usd')),
                'calls' => array_sum(array_column(array_filter($steps, fn ($step) => $step['key'] !== 'images'), 'calls')),
                'steps' => $steps,
            ];
        }

        return $result;
    }

    private static function step(string $action, AiUsageLog $row, int $sharedWith): array
    {
        return [
            'key' => $action,
            'label' => AiUsageLog::actionLabel($action),
            'calls' => (int) $row->calls,
            'failed' => (int) $row->failed,
            'cost_usd' => (float) $row->cost_usd / $sharedWith,
            'input_tokens' => (int) $row->input_tokens,
            'output_tokens' => (int) $row->output_tokens,
            'avg_duration_ms' => (int) round((float) $row->avg_duration_ms),
            'shared_with' => $sharedWith > 1 ? $sharedWith : null,
            'note' => $sharedWith > 1 ? "Chia đều cho {$sharedWith} video dùng chung." : null,
        ];
    }

    private static function merge(array $a, array $b): array
    {
        $a['avg_duration_ms'] = (int) round(($a['avg_duration_ms'] * $a['calls'] + $b['avg_duration_ms'] * $b['calls']) / max(1, $a['calls'] + $b['calls']));
        foreach (['calls', 'failed', 'cost_usd', 'input_tokens', 'output_tokens'] as $key) {
            $a[$key] += $b[$key];
        }

        return [...$a, 'shared_with' => $b['shared_with'], 'note' => $b['note']];
    }
}
