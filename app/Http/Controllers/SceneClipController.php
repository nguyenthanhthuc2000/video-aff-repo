<?php

namespace App\Http\Controllers;

use App\Enums\SceneClipStatus;
use App\Models\Scene;
use App\Models\VideoProject;
use App\Support\Media;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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

        $count = $project->queueMissingClips(retryFailed: true);

        return back()->with('status', $count > 0 ? "Muse đang tạo {$count} clip chuyển động..." : 'Không còn cảnh nào cần tạo clip (cần ảnh đã duyệt).');
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

        $scene->queueClip();

        return back()->with('status', "Muse đang tạo clip cảnh {$scene->position}...");
    }

    private static function blocker(VideoProject $project): ?string
    {
        return match (true) {
            ! VideoProject::clipsAvailable() => 'Tạo clip tự động cần MUSE_DRIVER=browser (đăng nhập muse.ai ở màn hình Xác thực Muse).',
            $project->motionStyles() === [] => 'Dự án chưa chọn kiểu chuyển động.',
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
