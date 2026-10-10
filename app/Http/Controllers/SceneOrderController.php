<?php

namespace App\Http\Controllers;

use App\Models\VideoProject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Đổi thứ tự các cảnh trước khi xuất video (video ghép cảnh theo thứ tự position). */
class SceneOrderController extends Controller
{
    public function update(Request $request, VideoProject $project): RedirectResponse
    {
        $this->authorize('update', $project);

        if ($project->status->isBusy()) {
            return back()->with('status', 'Dự án đang xử lý, đổi thứ tự cảnh sau.');
        }

        $validated = $request->validate([
            'scene_ids' => ['required', 'array'],
            'scene_ids.*' => ['integer', 'distinct'],
        ]);

        // Phải gửi đủ và đúng các cảnh của dự án.
        $ids = array_map(intval(...), $validated['scene_ids']);
        $current = $project->scenes()->pluck('id')->all();
        if (count($ids) !== count($current) || array_diff($ids, $current) !== []) {
            throw ValidationException::withMessages(['scene_ids' => 'Danh sách cảnh không khớp với dự án, tải lại trang rồi thử lại.']);
        }

        DB::transaction(function () use ($project, $ids) {
            foreach ($ids as $i => $id) {
                $project->scenes()->whereKey($id)->update(['position' => $i + 1]);
            }
            $project->touch();
        });

        return back()->with('status', 'Đã đổi thứ tự cảnh. Xuất lại video để áp dụng.');
    }
}
