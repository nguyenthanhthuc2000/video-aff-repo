<?php

namespace App\Http\Controllers;

use App\Enums\SceneImageStatus;
use App\Enums\VideoProjectStatus;
use App\Jobs\GenerateSceneImageJob;
use App\Models\Scene;
use App\Models\SceneImage;
use App\Models\VideoProject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SceneImageController extends Controller
{
    private const IMAGING_ALLOWED = [
        VideoProjectStatus::ScriptReady,
        VideoProjectStatus::Imaging,
        VideoProjectStatus::Reviewing,
        VideoProjectStatus::Done,
        VideoProjectStatus::Failed,
    ];

    /** Tạo ảnh cho mọi cảnh chưa có ảnh, hoặc ảnh gần nhất bị lỗi / đã bỏ. */
    public function store(VideoProject $project): RedirectResponse
    {
        $this->authorize('update', $project);

        if (! in_array($project->status, self::IMAGING_ALLOWED, true) || $project->scenes()->doesntExist()) {
            return back()->with('status', 'Cần có kịch bản trước khi tạo ảnh.');
        }

        $scenes = $project->scenes()
            ->with('latestImage')
            ->get()
            ->filter(fn (Scene $scene) => in_array($scene->latestImage?->status, [null, SceneImageStatus::Failed, SceneImageStatus::Rejected], true));

        if ($scenes->isEmpty()) {
            return back()->with('status', 'Mọi cảnh đã có ảnh.');
        }

        $project->update(['status' => VideoProjectStatus::Imaging, 'error' => null]);

        // Tạo hết các dòng "pending" trước rồi mới đưa job vào hàng đợi,
        // để dự án không chuyển sang bước duyệt khi ảnh đầu tiên xong.
        $images = $scenes->map(fn (Scene $scene) => $scene->images()->create());
        $images->each(fn (SceneImage $image) => GenerateSceneImageJob::dispatch($image));

        return back()->with('status', "Đang tạo {$images->count()} ảnh cảnh...");
    }

    /** Tạo lại ảnh một cảnh, có thể kèm prompt mới. */
    public function regenerate(Request $request, Scene $scene): RedirectResponse
    {
        $project = $scene->videoProject;
        $this->authorize('update', $project);

        $validated = $request->validate([
            'image_prompt' => ['nullable', 'string', 'max:2000'],
        ]);

        if (! in_array($project->status, self::IMAGING_ALLOWED, true)) {
            return back()->with('status', 'Không thể tạo ảnh ở bước này.');
        }

        $latest = $scene->latestImage;
        if ($latest?->status === SceneImageStatus::Pending) {
            return back()->with('status', 'Ảnh cảnh này đang được tạo.');
        }

        if (filled($validated['image_prompt'] ?? null)) {
            $scene->update(['image_prompt' => $validated['image_prompt']]);
        }

        $scene->images()
            ->whereIn('status', [SceneImageStatus::Ready, SceneImageStatus::Approved])
            ->update(['status' => SceneImageStatus::Rejected]);

        $project->update(['status' => VideoProjectStatus::Imaging, 'error' => null]);

        GenerateSceneImageJob::dispatch($scene->images()->create());

        return back()->with('status', 'Đang tạo lại ảnh cảnh '.$scene->position.'...');
    }

    public function approve(SceneImage $image): RedirectResponse
    {
        $scene = $image->scene;
        $this->authorize('update', $scene->videoProject);

        if (! in_array($image->status, [SceneImageStatus::Ready, SceneImageStatus::Approved], true)) {
            return back()->with('status', 'Chỉ duyệt được ảnh đã tạo xong.');
        }

        $scene->images()
            ->whereKeyNot($image->id)
            ->where('status', SceneImageStatus::Approved)
            ->update(['status' => SceneImageStatus::Rejected]);

        $image->update(['status' => SceneImageStatus::Approved]);

        return back();
    }
}
