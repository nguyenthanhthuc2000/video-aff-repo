<?php

namespace App\Http\Controllers\Admin;

use App\Enums\VideoProjectStatus;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateSceneImageJob;
use App\Models\AiUsageLog;
use App\Models\Scene;
use App\Models\SceneImage;
use App\Models\VideoProject;
use App\Services\Claude\AiUsage;
use App\Support\Media;
use App\Support\MotionCopy;
use App\Support\ProjectCost;
use App\Support\ProjectCredits;
use App\Support\ProjectProfit;
use App\Support\ProjectTimeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Dự án video của mọi người dùng (chỉ xem): theo dõi lỗi, tiến độ, chi phí xử lý từng video. */
class ProjectController extends Controller
{
    public function index(Request $request): Response
    {
        $status = VideoProjectStatus::tryFrom((string) $request->query('status'));
        $search = trim((string) $request->query('q'));
        $sort = $request->query('sort') === 'cost' ? 'cost' : 'latest';

        $projects = VideoProject::query()
            ->with(['user:id,name,email', 'product:id,name', 'latestDoneRender'])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->whereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%"))
                ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$search}%"))))
            // "Tốn nhất": theo chi phí Claude gắn trực tiếp với dự án.
            ->when($sort === 'cost', fn ($query) => $query->withSum('aiUsageLogs as ai_cost_usd', 'cost_usd')->orderByDesc('ai_cost_usd'))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        $costs = ProjectCost::forProjects($projects->getCollection());
        $timelines = ProjectTimeline::forProjects($projects->getCollection());
        $projects->through(fn (VideoProject $project) => [
            'id' => $project->id,
            'product' => $project->product?->name,
            'user' => ['name' => $project->user?->name, 'email' => $project->user?->email],
            'status' => $project->status->value,
            'video_type' => $project->video_type->value,
            'progress' => $project->progress(),
            'error' => $project->error,
            'has_video' => $project->latestDoneRender !== null,
            'created_at' => $project->created_at,
            'updated_at' => $project->updated_at,
            'cost' => $costs[$project->id] ?? ['total_usd' => 0, 'calls' => 0, 'steps' => []],
            'total_ms' => $timelines[$project->id]['total_ms'] ?? null,
        ]);

        return Inertia::render('admin/projects', [
            'projects' => $projects,
            'filters' => ['status' => $status?->value, 'q' => $search, 'sort' => $sort],
            'usdVnd' => AiUsage::settings()['usd_vnd'],
            // Thống kê thời gian xử lý 200 video gần nhất (đã xuất xong).
            'timeStats' => ProjectTimeline::stats(VideoProject::query()->latest()->limit(200)->get()),
        ]);
    }

    /** Chi tiết video cho admin: prompt từng cảnh, các lần tạo ảnh / clip, thời gian từng bước, chi phí. */
    public function show(VideoProject $project): Response
    {
        $project->load([
            'user:id,name,email', 'product.primaryImage', 'fashionModel', 'beforeFashionModel', 'sampleVideo', 'audioTrack',
            'scenes.images', 'scenes.background', 'scenes.sampleVideo', 'renders',
        ]);
        $iso = fn ($value) => $value?->toIso8601String();
        $duration = fn ($from, $to) => $from && $to ? max(0, (int) $from->diffInMilliseconds($to)) : null;

        return Inertia::render('admin/project-show', [
            'project' => [
                'id' => $project->id,
                'status' => $project->status->value,
                'video_type' => $project->video_type->value,
                'progress' => $project->progress(),
                'error' => $project->error,
                'created_at' => $iso($project->created_at),
                'user' => ['id' => $project->user?->id, 'name' => $project->user?->name, 'email' => $project->user?->email],
                'product' => ['id' => $project->product?->id, 'name' => $project->product?->name, 'image_url' => Media::url($project->product?->primaryImage?->path)],
                'fashion_model' => $project->fashionModel ? ['name' => $project->fashionModel->name, 'photo_url' => Media::url($project->fashionModel->photo_path)] : null,
                'before_fashion_model' => $project->beforeFashionModel ? ['name' => $project->beforeFashionModel->name, 'photo_url' => Media::url($project->beforeFashionModel->photo_path)] : null,
                'motion_styles' => collect($project->motionStyles())->map->label()->values()->all(),
                'sample_video' => $project->sampleVideo?->name,
                'audio_track' => $project->audioTrack?->name,
                'backgrounds' => $project->chosenBackgrounds()->map->present()->all(),
                'script' => $project->script,
                'selected_hook' => $project->selected_hook,
            ],
            'scenes' => $project->scenes->map(fn (Scene $scene) => [
                'id' => $scene->id,
                'position' => $scene->position,
                'phase' => $scene->phase?->value,
                'duration_ms' => $scene->duration_ms,
                'visual' => $scene->visual,
                'on_screen_text' => $scene->on_screen_text,
                'voiceover' => $scene->voiceover,
                'image_prompt' => $scene->image_prompt,
                'full_prompt' => GenerateSceneImageJob::scenePrompt($scene),
                'background' => $scene->background?->present(),
                'motion' => $scene->motionReference() ? 'Sao chép: '.$scene->motionReference()->name : $project->motionStyleFor($scene)?->label(),
                // Prompt clip đã gửi (bản ghi từ nay); chưa gửi thì là prompt sẽ gửi.
                'motion_prompt' => $scene->clip_prompt ?? ($project->hasMotion() ? ($scene->motionReference() ? MotionCopy::prompt($scene) : $project->motionStyleFor($scene)?->prompt($scene)) : null),
                'motion_prompt_sent' => $scene->clip_prompt !== null,
                'clip' => [
                    'status' => $scene->clip_status?->value,
                    'url' => Media::url($scene->clip_path),
                    'request_id' => $scene->clip_request_id,
                    'error' => $scene->clip_error,
                    'started_at' => $iso($scene->clip_started_at),
                    'duration_ms' => $duration($scene->clip_started_at, $scene->clip_completed_at),
                ],
                'images' => $scene->images->sortByDesc('id')->values()->map(fn (SceneImage $image) => [
                    'id' => $image->id,
                    'status' => $image->status->value,
                    'url' => Media::url($image->path),
                    'provider' => $image->provider,
                    'request_id' => $image->provider_request_id,
                    'prompt' => $image->prompt,
                    'error' => $image->error,
                    'created_at' => $iso($image->created_at),
                    'duration_ms' => $image->status->value === 'pending' ? null : $duration($image->created_at, $image->completed_at ?? $image->updated_at),
                ]),
            ]),
            'renders' => $project->renders->sortByDesc('id')->values()->map(fn ($render) => [
                'id' => $render->id,
                'status' => $render->status->value,
                'url' => Media::url($render->path),
                'duration_ms' => $render->duration_ms,
                'error' => $render->error,
                'created_at' => $iso($render->created_at),
                'processing_ms' => $render->status->value === 'pending' ? null : $duration($render->created_at, $render->completed_at ?? $render->updated_at),
            ]),
            'timeline' => ProjectTimeline::forProjects(collect([$project]))[$project->id],
            'cost' => ProjectCost::forProjects(collect([$project]))[$project->id],
            'credits' => ProjectCredits::forProjects(collect([$project]))[$project->id],
            'profit' => ProjectProfit::for($project),
            'usdVnd' => AiUsage::settings()['usd_vnd'],
        ]);
    }

    /** Từng lượt gọi Claude của dự án (kể cả bước dùng chung: đọc trang sản phẩm, phân tích video mẫu) cho popup chi phí. */
    public function aiCalls(VideoProject $project): JsonResponse
    {
        $subjects = array_filter(["product:{$project->product_id}", $project->sample_video_id ? "sample_video:{$project->sample_video_id}" : null]);

        $calls = AiUsageLog::query()
            ->where(fn ($query) => $query->where('video_project_id', $project->id)->orWhereIn('subject', $subjects))
            ->latest('id')->limit(100)->get()
            ->map(fn (AiUsageLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'label' => AiUsageLog::actionLabel($log->action),
                'model' => $log->model,
                'success' => $log->success,
                'input_tokens' => $log->input_tokens + $log->cache_write_tokens + $log->cache_read_tokens,
                'output_tokens' => $log->output_tokens,
                'cost_usd' => (float) $log->cost_usd,
                'duration_ms' => $log->duration_ms,
                'error' => $log->error,
                'shared' => $log->video_project_id !== $project->id,
                'subject' => $log->subject,
                'created_at' => $log->created_at,
            ]);

        return response()->json(['calls' => $calls]);
    }
}
