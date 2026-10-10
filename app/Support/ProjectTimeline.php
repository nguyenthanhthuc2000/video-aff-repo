<?php

namespace App\Support;

use App\Enums\RenderStatus;
use App\Enums\SceneClipStatus;
use App\Enums\SceneImageStatus;
use App\Models\Render;
use App\Models\Scene;
use App\Models\SceneImage;
use App\Models\VideoProject;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Thời gian xử lý từng video (admin): mỗi bước tính từ lúc bắt đầu đến lúc xong (thời gian thực, gồm cả lúc chờ hàng đợi),
 * tổng = từ lúc tạo dự án đến lúc xuất video xong lần đầu (gồm cả thời gian chờ người dùng duyệt).
 */
class ProjectTimeline
{
    public const STEPS = [
        'script' => 'Viết kịch bản',
        'images' => 'Tạo ảnh cảnh',
        'clips' => 'Tạo clip chuyển động',
        'render' => 'Xuất video',
    ];

    /**
     * @param  Collection<int, VideoProject>  $projects
     * @return array<int, array{total_ms: ?int, done: bool, steps: array<string, array{label: string, started_at: ?string, ended_at: ?string, duration_ms: ?int, count: int}>}>
     */
    public static function forProjects(Collection $projects): array
    {
        if ($projects->isEmpty()) {
            return [];
        }
        $ids = $projects->pluck('id');

        $scenes = Scene::whereIn('video_project_id', $ids)->selectRaw('video_project_id, min(created_at) as first_at, count(*) as total')
            ->groupBy('video_project_id')->get()->keyBy('video_project_id');
        $images = SceneImage::query()->join('scenes', 'scenes.id', '=', 'scene_images.scene_id')->whereIn('scenes.video_project_id', $ids)
            ->selectRaw('scenes.video_project_id, min(scene_images.created_at) as started, count(*) as total, '
                .'max(case when scene_images.status <> ? then coalesce(scene_images.completed_at, scene_images.updated_at) end) as ended, '
                .'sum(case when scene_images.status = ? then 1 else 0 end) as pending', [SceneImageStatus::Pending->value, SceneImageStatus::Pending->value])
            ->groupBy('scenes.video_project_id')->get()->keyBy('video_project_id');
        $clips = Scene::whereIn('video_project_id', $ids)->whereNotNull('clip_started_at')
            ->selectRaw('video_project_id, min(clip_started_at) as started, max(clip_completed_at) as ended, count(*) as total, '
                .'sum(case when clip_status = ? then 1 else 0 end) as pending', [SceneClipStatus::Pending->value])
            ->groupBy('video_project_id')->get()->keyBy('video_project_id');
        $renders = Render::whereIn('video_project_id', $ids)
            ->selectRaw('video_project_id, min(created_at) as started, count(*) as total, '
                .'min(case when status = ? then coalesce(completed_at, updated_at) end) as first_done', [RenderStatus::Done->value])
            ->groupBy('video_project_id')->get()->keyBy('video_project_id');

        $ms = fn ($from, $to) => $from && $to ? max(0, (int) Carbon::parse($from)->diffInMilliseconds(Carbon::parse($to))) : null;
        $iso = fn ($value) => $value ? Carbon::parse($value)->toIso8601String() : null;

        $result = [];
        foreach ($projects as $project) {
            $scene = $scenes[$project->id] ?? null;
            $image = $images[$project->id] ?? null;
            $clip = $clips[$project->id] ?? null;
            $render = $renders[$project->id] ?? null;

            $rows = [
                'script' => [$project->created_at, $scene?->first_at, $scene ? 1 : 0],
                'images' => [$image?->started, ($image && (int) $image->pending === 0) ? $image->ended : null, (int) ($image->total ?? 0)],
                'clips' => [$clip?->started, ($clip && (int) $clip->pending === 0) ? $clip->ended : null, (int) ($clip->total ?? 0)],
                'render' => [$render?->started, $render?->first_done, (int) ($render->total ?? 0)],
            ];
            $steps = [];
            foreach ($rows as $key => [$started, $ended, $count]) {
                if ($key === 'script' && ! $scene) {
                    $started = $project->script ? $project->created_at : ($project->status->value === 'scripting' ? $project->created_at : null);
                }
                $steps[$key] = [
                    'label' => self::STEPS[$key],
                    'started_at' => $iso($started),
                    'ended_at' => $iso($ended),
                    'duration_ms' => $ms($started, $ended),
                    'count' => $count,
                ];
            }

            $result[$project->id] = [
                'done' => $render?->first_done !== null,
                'total_ms' => $ms($project->created_at, $render?->first_done),
                'steps' => $steps,
            ];
        }

        return $result;
    }

    /**
     * Thống kê các video đã xuất xong: trung bình, trung vị, nhanh / chậm nhất, trung bình từng bước.
     *
     * @param  Collection<int, VideoProject>  $projects
     */
    public static function stats(Collection $projects): array
    {
        $timelines = collect(self::forProjects($projects))->filter(fn ($timeline) => $timeline['done']);
        $totals = $timelines->pluck('total_ms')->filter()->sort()->values();
        $median = fn (Collection $values) => $values->isEmpty() ? null : (int) $values->median();

        $byType = $projects->groupBy(fn (VideoProject $project) => $project->video_type->value)
            ->map(fn (Collection $group) => $timelines->only($group->pluck('id')->all())->pluck('total_ms')->filter())
            ->map(fn (Collection $values) => ['count' => $values->count(), 'avg_ms' => $values->isEmpty() ? null : (int) $values->avg()]);

        return [
            'count' => $totals->count(),
            'avg_ms' => $totals->isEmpty() ? null : (int) $totals->avg(),
            'median_ms' => $median($totals),
            'min_ms' => $totals->first(),
            'max_ms' => $totals->last(),
            'steps' => collect(self::STEPS)->map(function ($label, $key) use ($timelines, $median) {
                $values = $timelines->pluck("steps.{$key}.duration_ms")->filter(fn ($value) => $value !== null)->sort()->values();

                return ['label' => $label, 'count' => $values->count(), 'avg_ms' => $values->isEmpty() ? null : (int) $values->avg(), 'median_ms' => $median($values)];
            })->all(),
            'by_type' => $byType->all(),
        ];
    }
}
