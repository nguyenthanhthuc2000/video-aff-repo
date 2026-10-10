<?php

namespace App\Http\Controllers;

use App\Enums\FashionModelKind;
use App\Enums\MotionStyle;
use App\Enums\VideoProjectStatus;
use App\Enums\VideoType;
use App\Http\Resources\FashionModelResource;
use App\Http\Resources\VideoProjectResource;
use App\Models\VideoProject;
use App\Services\Video\SampleAudio;
use App\Support\CreditPricing;
use App\Support\Credits;
use App\Support\ProjectCredits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class VideoProjectController extends Controller
{
    /** Danh sách dự án, lọc theo trạng thái (?status=...), kèm số dự án mỗi trạng thái. */
    public function index(Request $request): Response
    {
        $status = VideoProjectStatus::tryFrom((string) $request->query('status'));

        $projects = $request->user()->videoProjects()
            ->with(['product.primaryImage', 'fashionModel', 'beforeFashionModel', 'latestDoneRender'])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->latest()
            ->get();

        $counts = $request->user()->videoProjects()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($total) => (int) $total);

        return Inertia::render('projects/index', [
            'projects' => VideoProjectResource::collection($projects)->resolve(),
            'filters' => ['status' => $status?->value],
            'statusCounts' => (object) $counts->all(),
            // Chi phí sản xuất từng video (Credits, theo bảng giá bán).
            'credits' => (object) ProjectCredits::forProjects($projects),
            'total' => $counts->sum(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $userId = $request->user()->id;

        $validated = $request->validate([
            'product_id' => ['required', Rule::exists('products', 'id')->where('user_id', $userId)],
            // Ảnh chính (sau) phải là người mẫu loại "sau"; ảnh "trước" của video biến hình là loại "trước".
            'fashion_model_id' => [
                'required',
                Rule::exists('fashion_models', 'id')->where('user_id', $userId)->whereNotNull('consent_at')->where('kind', FashionModelKind::After->value),
            ],
            // motion_style: một kiểu (bản cũ); motion_styles: nhiều kiểu, các cảnh dùng lần lượt.
            'motion_style' => ['nullable', Rule::enum(MotionStyle::class)],
            'motion_styles' => ['nullable', 'array', 'max:'.count(MotionStyle::cases())],
            'motion_styles.*' => ['distinct', Rule::enum(MotionStyle::class)],
            'autopilot' => ['boolean'],
            'video_type' => ['nullable', Rule::enum(VideoType::class)],
            'audio_track_id' => ['nullable', Rule::exists('audio_tracks', 'id')->where('user_id', $userId)->whereNotNull('path')],
            // Sao chép chuyển động từ video mẫu cho mọi cảnh.
            'sample_video_id' => ['nullable', Rule::exists('sample_videos', 'id')->where('user_id', $userId)],
            // "Nhạc như video mẫu": tách tiếng của video mẫu đã chọn làm nhạc nền.
            'use_sample_audio' => ['boolean'],
            // Bối cảnh: trống = AI tự đề xuất theo sản phẩm; có danh sách = dùng bối cảnh của người dùng (các cảnh dùng lần lượt).
            'background_ids' => ['nullable', 'array', 'max:20'],
            'background_ids.*' => ['integer', 'distinct', Rule::exists('backgrounds', 'id')->where('user_id', $userId)],
            // Biến hình: ảnh "trước" (đồ cũ, mặt mộc, tóc rối) là một người mẫu đã đồng ý của chính người dùng.
            'before_fashion_model_id' => [
                Rule::requiredIf($request->input('video_type') === VideoType::Transformation->value),
                'nullable',
                Rule::exists('fashion_models', 'id')->where('user_id', $userId)->whereNotNull('consent_at')->where('kind', FashionModelKind::Before->value),
            ],
        ], [
            'before_fashion_model_id.required' => 'Chọn ảnh "trước" cho video biến hình.',
        ], [
            'before_fashion_model_id' => 'ảnh trước',
            'audio_track_id' => 'nhạc nền',
            'sample_video_id' => 'video mẫu',
            'background_ids' => 'bối cảnh',
            'background_ids.*' => 'bối cảnh',
            'product_id' => 'sản phẩm',
            'fashion_model_id' => 'người mẫu',
            'motion_style' => 'kiểu chuyển động',
            'motion_styles' => 'kiểu chuyển động',
            'motion_styles.*' => 'kiểu chuyển động',
        ]);

        if (! $request->user()->products()->findOrFail($validated['product_id'])->imagesApproved()) {
            throw ValidationException::withMessages(['product_id' => 'Duyệt ảnh sản phẩm trước khi tạo video.']);
        }

        $validated['video_type'] ??= VideoType::Standard->value;
        if ($validated['video_type'] !== VideoType::Transformation->value) {
            $validated['before_fashion_model_id'] = null;
        }

        if ($request->boolean('use_sample_audio')) {
            $sample = $request->user()->sampleVideos()->find($validated['sample_video_id'] ?? 0)
                ?? throw ValidationException::withMessages(['audio_track_id' => 'Chọn video mẫu để dùng nhạc của video mẫu.']);
            try {
                $validated['audio_track_id'] = app(SampleAudio::class)->extract($sample)->id;
            } catch (\RuntimeException $e) {
                throw ValidationException::withMessages(['audio_track_id' => $e->getMessage()]);
            }
        }
        unset($validated['use_sample_audio']);
        $validated['background_ids'] = array_values(array_map('intval', $validated['background_ids'] ?? [])) ?: null;

        $autopilot = (bool) ($validated['autopilot'] ?? false);
        $styles = array_values($validated['motion_styles'] ?? array_filter([$validated['motion_style'] ?? null]));
        $validated = [...$validated, 'motion_style' => $styles[0] ?? null, 'motion_styles' => $styles ?: null];
        unset($validated['autopilot']);
        $project = $request->user()->videoProjects()->create($validated);

        // Chạy ngầm: cần đủ Credits ít nhất cho bước viết kịch bản (các bước sau tự kiểm tra, thiếu thì dừng).
        if ($autopilot && ($message = Credits::shortfall($request->user(), CreditPricing::aiEstimates()['script'], 'chạy ngầm'))) {
            return to_route('projects.show', $project)->with('status', $message);
        }

        if ($autopilot && $project->autopilotBlocker() === null) {
            $project->startAutopilot();

            return to_route('projects.show', $project)->with('status', 'Đang chạy ngầm: viết kịch bản → tạo ảnh → xuất video.');
        }

        return to_route('projects.show', $project);
    }

    public function show(Request $request, VideoProject $project): Response
    {
        $this->authorize('view', $project);

        $project->load(['product.primaryImage', 'fashionModel', 'beforeFashionModel', 'audioTrack', 'sampleVideo', 'scenes.latestImage', 'scenes.sampleVideo', 'scenes.background', 'renders']);

        return Inertia::render('projects/show', [
            'credits' => ProjectCredits::forProjects(collect([$project]))[$project->id],
            'project' => VideoProjectResource::make($project)->resolve(),
            'autopilotAvailable' => self::autopilotAvailable(),
            // Cho nút "Tạo thêm video" (cùng sản phẩm).
            // Video mẫu để sao chép chuyển động.
            'sampleVideos' => $request->user()->sampleVideos()->latest()->get()
                ->map(fn ($video) => SampleVideoController::present($video)),
            // Nhạc trong thư viện (chỉ bài có file) để chọn khi tạo video.
            'audioTracks' => $request->user()->audioTracks()->whereNotNull('path')->latest()->get()
                ->map(fn ($track) => AudioTrackController::present($track)),
            'fashionModels' => FashionModelResource::collection(
                $request->user()->fashionModels()->whereNotNull('consent_at')->latest()->get()
            )->resolve(),
            'backgrounds' => $request->user()->backgrounds()->latest()->get()->map->present(),
        ]);
    }

    /** Chạy ngầm các bước còn lại (kịch bản, ảnh, xuất video). */
    public function autopilot(VideoProject $project): RedirectResponse
    {
        $this->authorize('update', $project);

        if ($project->status->isBusy()) {
            return back()->with('status', 'Dự án đang xử lý.');
        }

        if ($blocker = $project->autopilotBlocker()) {
            return back()->with('status', $blocker);
        }

        if ($message = Credits::shortfall($project->user, CreditPricing::aiEstimates()[$project->script ? 'scene_prompt_rewrite' : 'script'], 'chạy ngầm')) {
            return back()->with('status', $message);
        }

        $project->startAutopilot();

        return back()->with('status', 'Đang chạy ngầm, bạn có thể rời trang này.');
    }

    /** Có khoá API cho cả Claude và Muse: dự án mới chạy ngầm được từ đầu đến cuối. */
    public static function autopilotAvailable(): bool
    {
        return ! ScriptController::webMode() && ! SceneImageController::webMode();
    }

    public function destroy(VideoProject $project): RedirectResponse
    {
        $this->authorize('delete', $project);

        $project->delete();

        return to_route('projects.index')->with('status', 'Đã xoá dự án video.');
    }
}
