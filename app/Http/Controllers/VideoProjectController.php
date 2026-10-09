<?php

namespace App\Http\Controllers;

use App\Enums\MotionStyle;
use App\Http\Resources\FashionModelResource;
use App\Http\Resources\VideoProjectResource;
use App\Models\VideoProject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class VideoProjectController extends Controller
{
    public function index(Request $request): Response
    {
        $projects = $request->user()->videoProjects()
            ->with(['product.primaryImage', 'fashionModel'])
            ->latest()
            ->get();

        return Inertia::render('projects/index', [
            'projects' => VideoProjectResource::collection($projects)->resolve(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $userId = $request->user()->id;

        $validated = $request->validate([
            'product_id' => ['required', Rule::exists('products', 'id')->where('user_id', $userId)],
            'fashion_model_id' => [
                'required',
                Rule::exists('fashion_models', 'id')->where('user_id', $userId)->whereNotNull('consent_at'),
            ],
            // motion_style: một kiểu (bản cũ); motion_styles: nhiều kiểu, các cảnh dùng lần lượt.
            'motion_style' => ['nullable', Rule::enum(MotionStyle::class)],
            'motion_styles' => ['nullable', 'array', 'max:'.count(MotionStyle::cases())],
            'motion_styles.*' => ['distinct', Rule::enum(MotionStyle::class)],
            'autopilot' => ['boolean'],
        ], [], [
            'product_id' => 'sản phẩm',
            'fashion_model_id' => 'người mẫu',
            'motion_style' => 'kiểu chuyển động',
            'motion_styles' => 'kiểu chuyển động',
            'motion_styles.*' => 'kiểu chuyển động',
        ]);

        if (! $request->user()->products()->findOrFail($validated['product_id'])->imagesApproved()) {
            throw ValidationException::withMessages(['product_id' => 'Duyệt ảnh sản phẩm trước khi tạo video.']);
        }

        $autopilot = (bool) ($validated['autopilot'] ?? false);
        $styles = array_values($validated['motion_styles'] ?? array_filter([$validated['motion_style'] ?? null]));
        $validated = [...$validated, 'motion_style' => $styles[0] ?? null, 'motion_styles' => $styles ?: null];
        unset($validated['autopilot']);
        $project = $request->user()->videoProjects()->create($validated);

        if ($autopilot && $project->autopilotBlocker() === null) {
            $project->startAutopilot();

            return to_route('projects.show', $project)->with('status', 'Đang chạy ngầm: viết kịch bản → tạo ảnh → xuất video.');
        }

        return to_route('projects.show', $project);
    }

    public function show(Request $request, VideoProject $project): Response
    {
        $this->authorize('view', $project);

        $project->load(['product.primaryImage', 'fashionModel', 'scenes.latestImage', 'renders']);
        $project->loadSum('sceneImages as image_cost_usd', 'cost_usd');

        return Inertia::render('projects/show', [
            'project' => VideoProjectResource::make($project)->resolve(),
            'autopilotAvailable' => self::autopilotAvailable(),
            // Cho nút "Tạo thêm video" (cùng sản phẩm).
            'fashionModels' => FashionModelResource::collection(
                $request->user()->fashionModels()->whereNotNull('consent_at')->latest()->get()
            )->resolve(),
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
