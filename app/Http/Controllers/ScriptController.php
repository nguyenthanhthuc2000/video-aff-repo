<?php

namespace App\Http\Controllers;

use App\Enums\VideoProjectStatus;
use App\Jobs\GenerateScriptJob;
use App\Models\VideoProject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ScriptController extends Controller
{
    /** Đưa việc viết kịch bản vào hàng đợi. Viết lại sẽ xoá các cảnh cũ. */
    public function store(VideoProject $project): RedirectResponse
    {
        $this->authorize('update', $project);

        if (! in_array($project->status, [VideoProjectStatus::Draft, VideoProjectStatus::ScriptReady, VideoProjectStatus::Failed], true)) {
            return back()->with('status', 'Không thể viết lại kịch bản ở bước này.');
        }

        $project->update(['status' => VideoProjectStatus::Scripting, 'error' => null]);

        GenerateScriptJob::dispatch($project);

        return back()->with('status', 'Đang viết kịch bản...');
    }

    /** Lưu chỉnh sửa hook, caption và các cảnh. */
    public function update(Request $request, VideoProject $project): RedirectResponse
    {
        $this->authorize('update', $project);

        if ($project->status->isBusy() || $project->script === null) {
            return back()->with('status', 'Chưa thể sửa kịch bản lúc này.');
        }

        $sceneIds = $project->scenes()->pluck('id')->all();

        $validated = $request->validate([
            'hooks' => ['required', 'array', 'min:1', 'max:3'],
            'hooks.*' => ['required', 'string', 'max:200'],
            'selected_hook' => ['required', 'integer', 'min:0', 'lt:'.count($request->input('hooks', []))],
            'caption' => ['nullable', 'string', 'max:2000'],
            'hashtags' => ['array', 'max:10'],
            'hashtags.*' => ['string', 'max:50'],
            'scenes' => ['required', 'array'],
            'scenes.*.id' => ['required', 'integer', Rule::in($sceneIds)],
            'scenes.*.visual' => ['required', 'string', 'max:1000'],
            'scenes.*.on_screen_text' => ['nullable', 'string', 'max:200'],
            'scenes.*.voiceover' => ['nullable', 'string', 'max:1000'],
            'scenes.*.image_prompt' => ['required', 'string', 'max:2000'],
            'scenes.*.duration_ms' => ['required', 'integer', 'min:1000', 'max:10000'],
        ], [], [
            'hooks.*' => 'hook',
            'scenes.*.visual' => 'mô tả cảnh',
            'scenes.*.image_prompt' => 'prompt ảnh',
            'scenes.*.duration_ms' => 'thời lượng',
        ]);

        $project->update([
            'script' => [
                ...$project->script,
                'hooks' => array_values($validated['hooks']),
                'caption' => $validated['caption'] ?? '',
                'hashtags' => array_values($validated['hashtags'] ?? []),
            ],
            'selected_hook' => $validated['selected_hook'],
        ]);

        foreach ($validated['scenes'] as $data) {
            $project->scenes()->whereKey($data['id'])->update([
                'visual' => $data['visual'],
                'on_screen_text' => $data['on_screen_text'] ?? '',
                'voiceover' => $data['voiceover'] ?? '',
                'image_prompt' => $data['image_prompt'],
                'duration_ms' => $data['duration_ms'],
            ]);
        }

        return back()->with('status', 'Đã lưu kịch bản.');
    }
}
