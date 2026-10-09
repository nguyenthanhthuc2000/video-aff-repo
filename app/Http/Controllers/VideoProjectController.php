<?php

namespace App\Http\Controllers;

use App\Http\Resources\VideoProjectResource;
use App\Models\VideoProject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
        ], [], [
            'product_id' => 'sản phẩm',
            'fashion_model_id' => 'người mẫu',
        ]);

        $project = $request->user()->videoProjects()->create($validated);

        return to_route('projects.show', $project);
    }

    public function show(VideoProject $project): Response
    {
        $this->authorize('view', $project);

        $project->load(['product.primaryImage', 'fashionModel', 'scenes.latestImage', 'renders']);
        $project->loadSum('sceneImages as image_cost_usd', 'cost_usd');

        return Inertia::render('projects/show', [
            'project' => VideoProjectResource::make($project)->resolve(),
        ]);
    }

    public function destroy(VideoProject $project): RedirectResponse
    {
        $this->authorize('delete', $project);

        $project->delete();

        return to_route('projects.index')->with('status', 'Đã xoá dự án video.');
    }
}
