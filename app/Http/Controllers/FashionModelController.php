<?php

namespace App\Http\Controllers;

use App\Http\Resources\FashionModelResource;
use App\Models\FashionModel;
use App\Support\Media;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FashionModelController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('fashion-models/index', [
            'fashionModels' => FashionModelResource::collection(
                $request->user()->fashionModels()->latest()->get()
            )->resolve(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'photo' => ['required', 'image', 'max:10240'],
            'consent' => ['accepted'],
        ], [
            'consent.accepted' => 'Cần xác nhận người mẫu đã đồng ý cho dùng hình ảnh.',
        ], [
            'name' => 'tên',
            'photo' => 'ảnh',
        ]);

        $request->user()->fashionModels()->create([
            'name' => $validated['name'],
            'photo_path' => Media::store($request->file('photo'), 'models'),
            'consent_at' => now(),
        ]);

        return back()->with('status', 'Đã thêm người mẫu.');
    }

    public function destroy(FashionModel $fashionModel): RedirectResponse
    {
        $this->authorize('delete', $fashionModel);

        if ($fashionModel->videoProjects()->exists()) {
            return back()->withErrors(['fashion_model' => 'Người mẫu đang được dùng trong dự án video, không xoá được.']);
        }

        $fashionModel->delete();
        Media::delete($fashionModel->photo_path);

        return back()->with('status', 'Đã xoá người mẫu.');
    }
}
