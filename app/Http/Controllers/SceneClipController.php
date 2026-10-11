<?php

namespace App\Http\Controllers;

use App\Enums\SceneClipStatus;
use App\Models\Scene;
use App\Models\VideoProject;
use App\Support\Credits;
use App\Support\Media;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Clip chuyển động của cảnh (muse.ai tạo từ ảnh cảnh đã duyệt, hoặc tải lên). Khi xuất video, clip thay cho ảnh tĩnh. */
class SceneClipController extends Controller
{
    /** Nhờ muse.ai tạo clip cho mọi cảnh có ảnh duyệt mà chưa có clip (kể cả cảnh lỗi trước đó). */
    public function generateAll(VideoProject $project): RedirectResponse
    {
        $this->authorize('update', $project);

        if ($blocker = self::blocker($project)) {
            return back()->with('status', $blocker);
        }

        $needed = $project->scenesMissingClips(retryFailed: true)->sum(fn ($scene) => $project->clipPrice($scene));
        if ($message = Credits::shortfall($project->user, $needed, 'tạo clip chuyển động')) {
            return back()->with('status', $message);
        }

        $count = $project->queueMissingClips(retryFailed: true);

        return back()->with('status', $count > 0 ? "AI đang tạo {$count} clip chuyển động..." : 'Không còn cảnh nào cần tạo clip (cần ảnh đã duyệt).');
    }

    /** Tạo (lại) clip cho một cảnh. */
    public function generate(Scene $scene): RedirectResponse
    {
        $project = $scene->videoProject;
        $this->authorize('update', $project);

        if ($blocker = self::blocker($project)) {
            return back()->with('status', $blocker);
        }
        if ($scene->approvedImage === null) {
            return back()->with('status', 'Cần duyệt ảnh cảnh trước khi tạo clip.');
        }
        if ($scene->clip_status === SceneClipStatus::Pending) {
            return back()->with('status', 'Clip cảnh này đang được tạo.');
        }

        if ($message = Credits::shortfall($project->user, $project->clipPrice($scene), 'tạo clip chuyển động')) {
            return back()->with('status', $message);
        }

        $scene->queueClip();

        return back()->with('status', "AI đang tạo clip cảnh {$scene->position}...");
    }

    /** Video mẫu mặc định của dự án để sao chép chuyển động (null = dùng kiểu chuyển động có sẵn). */
    public function projectReference(Request $request, VideoProject $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate(self::referenceRules($request), [], ['sample_video_id' => 'video mẫu']);
        $project->update(['sample_video_id' => $validated['sample_video_id'] ?? null]);
        // Đổi video mẫu: viết lại kịch bản thì Muse xem video mới và lên lại phân cảnh.
        $project->forgetMusePlan();

        return back()->with('status', $project->sample_video_id
            ? 'Đã chọn video mẫu cho dự án. Tạo (lại) clip để sao chép chuyển động.'
            : 'Đã bỏ video mẫu, các cảnh dùng kiểu chuyển động có sẵn.');
    }

    /** Video mẫu riêng của một cảnh (null = theo dự án). */
    public function sceneReference(Request $request, Scene $scene): RedirectResponse
    {
        $this->authorize('update', $scene->videoProject);

        $validated = $request->validate(self::referenceRules($request), [], ['sample_video_id' => 'video mẫu']);
        $scene->update(['sample_video_id' => $validated['sample_video_id'] ?? null]);

        return back()->with('status', "Đã đổi chuyển động của cảnh {$scene->position}. Tạo lại clip để áp dụng.");
    }

    private static function referenceRules(Request $request): array
    {
        return ['sample_video_id' => ['nullable', Rule::exists('sample_videos', 'id')->where('user_id', $request->user()->id)]];
    }

    private static function blocker(VideoProject $project): ?string
    {
        return match (true) {
            ! VideoProject::clipsAvailable() => 'Tạo clip tự động chưa sẵn sàng trên hệ thống. Liên hệ quản trị viên, hoặc tự tạo clip rồi tải lên.',
            ! $project->hasMotion() => 'Dự án chưa chọn kiểu chuyển động hoặc video mẫu.',
            $project->status->isBusy() => 'Dự án đang xử lý, thử lại sau.',
            default => null,
        };
    }

    public function store(Request $request, Scene $scene): RedirectResponse
    {
        $project = $scene->videoProject;
        $this->authorize('update', $project);

        $request->validate([
            'clip' => ['required', 'file', 'mimetypes:video/mp4,video/quicktime,video/webm', 'max:102400'],
        ], [], ['clip' => 'clip']);

        if ($project->status->isBusy()) {
            return back()->with('status', 'Dự án đang xử lý, thử lại sau.');
        }

        $old = $scene->clip_path;
        $scene->update(['clip_path' => Media::store($request->file('clip'), "clips/{$project->id}"), 'clip_status' => null, 'clip_error' => null]);
        Media::delete($old);

        return back()->with('status', "Đã tải clip cảnh {$scene->position}.");
    }

    public function destroy(Scene $scene): RedirectResponse
    {
        $this->authorize('update', $scene->videoProject);

        Media::delete($scene->clip_path);
        $scene->update(['clip_path' => null, 'clip_status' => null, 'clip_error' => null]);

        return back()->with('status', "Đã bỏ clip cảnh {$scene->position}, cảnh dùng lại ảnh tĩnh.");
    }
}
