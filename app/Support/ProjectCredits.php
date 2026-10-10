<?php

namespace App\Support;

use App\Enums\RenderStatus;
use App\Enums\SceneClipStatus;
use App\Enums\SceneImageStatus;
use App\Models\AiUsageLog;
use App\Models\CreditTransaction;
use App\Models\Render;
use App\Models\Scene;
use App\Models\SceneImage;
use App\Models\VideoProject;
use App\Services\Claude\AiUsage;
use Illuminate\Support\Collection;

/**
 * Chi phí sản xuất từng video (Credits), hiển thị cho người dùng.
 * Video đã bị trừ Credits: lấy đúng số đã trừ trong sổ Credits (trừ đi phần hoàn).
 * Video làm trước khi bật trừ Credits: ước tính theo bảng giá bán (estimated = true); bước dùng chung
 * (đọc sản phẩm, phân tích video mẫu) chia đều cho các video dùng chung; ảnh / clip tự tải lên không tính.
 */
class ProjectCredits
{
    public const LABELS = [
        'product_import' => 'Đọc thông tin sản phẩm',
        'motion_analysis' => 'Phân tích video mẫu',
        'script' => 'Viết kịch bản',
        'scene_prompt_rewrite' => 'Viết lại cảnh khi tạo lại ảnh',
        'images' => 'Ảnh cảnh',
        'clips' => 'Clip chuyển động',
        'renders' => 'Xuất video',
    ];

    /** Ảnh do người dùng tự tạo trên meta.ai rồi tải lên. */
    private const MANUAL_IMAGE_PROVIDERS = ['meta.ai-web'];

    /**
     * @param  Collection<int, VideoProject>  $projects
     * @return array<int, array{total: int, estimated: bool, steps: list<array{key: string, label: string, count: int, credits: int}>}>
     */
    public static function forProjects(Collection $projects): array
    {
        if ($projects->isEmpty()) {
            return [];
        }

        $charged = self::charged($projects);
        $estimated = self::estimated($projects->reject(fn ($project) => isset($charged[$project->id])));

        return $projects->mapWithKeys(fn ($project) => [$project->id => $charged[$project->id] ?? $estimated[$project->id]])->all();
    }

    /** Hành động trong sổ Credits → bước hiển thị. */
    private const CHARGE_STEPS = ['image' => 'images', 'clip' => 'clips', 'render' => 'renders'];

    /** @return array<int, array{total: int, estimated: bool, steps: list<array>}> Chỉ dự án đã có giao dịch trừ / hoàn. */
    private static function charged(Collection $projects): array
    {
        $rows = CreditTransaction::whereIn('video_project_id', $projects->pluck('id'))->whereIn('type', ['usage', 'refund'])
            ->get(['video_project_id', 'type', 'action', 'credits'])->groupBy('video_project_id');

        $order = array_keys(self::LABELS);
        $result = [];
        foreach ($rows as $projectId => $transactions) {
            $steps = [];
            foreach ($transactions as $row) {
                $key = self::CHARGE_STEPS[$row->action] ?? $row->action;
                if (! isset(self::LABELS[$key])) {
                    continue;
                }
                $steps[$key] ??= ['key' => $key, 'label' => self::LABELS[$key], 'count' => 0, 'credits' => 0];
                $steps[$key]['count'] += $row->type === 'usage' ? 1 : 0;
                $steps[$key]['credits'] -= $row->credits;
            }
            uksort($steps, fn ($a, $b) => array_search($a, $order, true) <=> array_search($b, $order, true));
            $steps = array_values($steps);
            $result[$projectId] = ['total' => array_sum(array_column($steps, 'credits')), 'estimated' => false, 'steps' => $steps];
        }

        return $result;
    }

    /** @return array<int, array{total: int, estimated: bool, steps: list<array>}> */
    private static function estimated(Collection $projects): array
    {
        if ($projects->isEmpty()) {
            return [];
        }

        $pricing = CreditPricing::settings();
        $usdVnd = AiUsage::settings()['usd_vnd'];
        $ids = $projects->pluck('id');
        $price = fn (AiUsageLog $log) => CreditPricing::forAiCall((float) $log->cost_usd, $log->model, $pricing, $usdVnd);

        $own = AiUsageLog::whereIn('video_project_id', $ids)->where('success', true)->get(['video_project_id', 'action', 'model', 'cost_usd'])->groupBy('video_project_id');
        $subjects = $projects->map(fn ($p) => "product:{$p->product_id}")
            ->merge($projects->pluck('sample_video_id')->filter()->map(fn ($id) => "sample_video:{$id}"))->unique();
        $shared = AiUsageLog::whereIn('subject', $subjects)->where('success', true)->get(['subject', 'action', 'model', 'cost_usd'])->groupBy('subject');
        $perProduct = VideoProject::whereIn('product_id', $projects->pluck('product_id')->unique())->selectRaw('product_id, count(*) as total')->groupBy('product_id')->pluck('total', 'product_id');
        $perSample = VideoProject::whereIn('sample_video_id', $projects->pluck('sample_video_id')->filter()->unique())->selectRaw('sample_video_id, count(*) as total')->groupBy('sample_video_id')->pluck('total', 'sample_video_id');

        $images = SceneImage::query()->join('scenes', 'scenes.id', '=', 'scene_images.scene_id')
            ->whereIn('scenes.video_project_id', $ids)
            ->whereIn('scene_images.status', [SceneImageStatus::Ready, SceneImageStatus::Approved, SceneImageStatus::Rejected])
            ->where(fn ($q) => $q->whereNull('scene_images.provider')->orWhereNotIn('scene_images.provider', self::MANUAL_IMAGE_PROVIDERS))
            ->selectRaw('scenes.video_project_id, count(*) as total')->groupBy('scenes.video_project_id')->pluck('total', 'video_project_id');
        // Clip do AI tạo (mã yêu cầu VA-...), không tính clip tự tải lên.
        $clips = Scene::whereIn('video_project_id', $ids)->where('clip_status', SceneClipStatus::Ready)->where('clip_request_id', 'like', 'VA-%')
            ->get(['video_project_id', 'sample_video_id'])->groupBy('video_project_id');
        $renders = Render::whereIn('video_project_id', $ids)->where('status', RenderStatus::Done)->get(['video_project_id', 'duration_ms'])->groupBy('video_project_id');

        $result = [];
        foreach ($projects as $project) {
            $steps = [];
            $add = function (string $key, int $count, int $credits) use (&$steps) {
                if (! isset(self::LABELS[$key]) || ($count <= 0 && $credits <= 0)) {
                    return;
                }
                $steps[$key] ??= ['key' => $key, 'label' => self::LABELS[$key], 'count' => 0, 'credits' => 0];
                $steps[$key]['count'] += $count;
                $steps[$key]['credits'] += $credits;
            };

            foreach ($own[$project->id] ?? [] as $log) {
                $add($log->action, 1, $price($log));
            }
            $sharedSources = [
                "product:{$project->product_id}" => max(1, (int) ($perProduct[$project->product_id] ?? 1)),
                "sample_video:{$project->sample_video_id}" => max(1, (int) ($perSample[$project->sample_video_id] ?? 1)),
            ];
            foreach ($sharedSources as $subject => $count) {
                foreach ($shared[$subject] ?? [] as $log) {
                    $add($log->action, 1, (int) ceil($price($log) / $count));
                }
            }

            $add('images', (int) ($images[$project->id] ?? 0), (int) ($images[$project->id] ?? 0) * $pricing['steps']['image']);
            foreach ($clips[$project->id] ?? [] as $clip) {
                $copy = $clip->sample_video_id !== null || $project->sample_video_id !== null;
                $add('clips', 1, $pricing['steps'][$copy ? 'clip_copy' : 'clip_style']);
            }
            foreach ($renders[$project->id] ?? [] as $render) {
                $add('renders', 1, $pricing['steps'][($render->duration_ms ?? 0) > 30000 ? 'render_long' : 'render']);
            }

            $order = array_keys(self::LABELS);
            uksort($steps, fn ($a, $b) => array_search($a, $order, true) <=> array_search($b, $order, true));
            $steps = array_values($steps);

            $result[$project->id] = ['total' => array_sum(array_column($steps, 'credits')), 'estimated' => true, 'steps' => $steps];
        }

        return $result;
    }
}
