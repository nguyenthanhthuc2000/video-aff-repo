<?php

namespace App\Http\Controllers;

use App\Enums\RenderStatus;
use App\Enums\VideoProjectStatus;
use App\Jobs\RenderVideoJob;
use App\Models\Render;
use App\Models\VideoProject;
use App\Support\Media;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RenderController extends Controller
{
    public function store(Request $request, VideoProject $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'hook' => ['required', 'integer', 'min:0', 'lt:'.count($project->script['hooks'] ?? [])],
            'music' => ['nullable', 'file', 'mimes:mp3,m4a,aac,wav', 'max:20480'],
            'remove_music' => ['boolean'],
        ], [], ['music' => 'nhạc nền']);

        if (! in_array($project->status, [VideoProjectStatus::Reviewing, VideoProjectStatus::Done, VideoProjectStatus::Failed], true)
            || ! $project->allScenesApproved()) {
            return back()->with('status', 'Cần duyệt ảnh cho mọi cảnh trước khi xuất video.');
        }

        if ($request->hasFile('music') || $request->boolean('remove_music')) {
            Media::delete($project->music);
            $project->music = $request->hasFile('music') ? Media::store($request->file('music'), 'music') : null;
        }

        $project->fill([
            'selected_hook' => $validated['hook'],
            'status' => VideoProjectStatus::Rendering,
            'error' => null,
        ])->save();

        $render = $project->renders()->create(['hook' => $validated['hook'], 'status' => RenderStatus::Pending]);

        RenderVideoJob::dispatch($render);

        return back()->with('status', 'Đang xuất video...');
    }

    public function download(Render $render): StreamedResponse
    {
        $this->authorize('view', $render->videoProject);

        abort_unless($render->status === RenderStatus::Done && $render->path, 404);

        return Media::disk()->download($render->path, "video-aff-{$render->video_project_id}-{$render->id}.mp4");
    }
}
