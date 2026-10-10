<?php

namespace App\Http\Controllers;

use App\Enums\FashionModelKind;
use App\Http\Resources\FashionModelResource;
use App\Models\FashionModel;
use App\Models\VideoProject;
use App\Support\Media;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

    /**
     * Thêm một hoặc nhiều người mẫu (mỗi ảnh một người mẫu). Không nhập tên thì đặt "Mẫu 1", "Mẫu 2"...
     * tiếp theo số lớn nhất đã có; nhập tên mà có nhiều ảnh thì thành "Tên 1", "Tên 2"...
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'kind' => ['nullable', Rule::enum(FashionModelKind::class)],
            'photos' => ['required', 'array', 'min:1', 'max:20'],
            'photos.*' => ['image', 'max:10240'],
            'consent' => ['accepted'],
        ], [
            'consent.accepted' => 'Cần xác nhận người mẫu đã đồng ý cho dùng hình ảnh.',
            'photos.required' => 'Chọn ít nhất 1 ảnh.',
        ], [
            'name' => 'tên',
            'photos' => 'ảnh',
            'photos.*' => 'ảnh',
        ]);

        $photos = $request->file('photos');
        $name = trim((string) ($validated['name'] ?? ''));
        $kind = FashionModelKind::tryFrom((string) ($validated['kind'] ?? '')) ?? FashionModelKind::After;
        $next = $this->nextDefaultNumber($request, $kind);

        foreach (array_values($photos) as $i => $photo) {
            $request->user()->fashionModels()->create([
                'name' => match (true) {
                    $name === '' => $kind->defaultNamePrefix().' '.($next + $i),
                    count($photos) === 1 => $name,
                    default => "{$name} ".($i + 1),
                },
                'kind' => $kind,
                'photo_path' => Media::store($photo, 'models'),
                'consent_at' => now(),
            ]);
        }

        return back()->with('status', count($photos) === 1 ? 'Đã thêm người mẫu.' : 'Đã thêm '.count($photos).' người mẫu.');
    }

    /** Số tiếp theo cho tên mặc định "Mẫu N" / "Mẫu trước N" (theo số lớn nhất đã dùng, không trùng khi đã xoá bớt). */
    private function nextDefaultNumber(Request $request, FashionModelKind $kind): int
    {
        $prefix = $kind->defaultNamePrefix();
        $max = $request->user()->fashionModels()->where('name', 'like', "{$prefix} %")->pluck('name')
            ->map(fn (string $name) => preg_match('/^'.preg_quote($prefix, '/').' (\d+)$/u', $name, $m) ? (int) $m[1] : 0)
            ->max() ?? 0;

        return $max + 1;
    }

    /** Đổi loại (trước / sau) hoặc tên người mẫu. */
    public function update(Request $request, FashionModel $fashionModel): RedirectResponse
    {
        $this->authorize('update', $fashionModel);

        $fashionModel->update($request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'kind' => ['sometimes', 'required', Rule::enum(FashionModelKind::class)],
        ], [], ['name' => 'tên', 'kind' => 'loại']));

        return back()->with('status', "Đã lưu {$fashionModel->name}.");
    }

    public function destroy(FashionModel $fashionModel): RedirectResponse
    {
        $this->authorize('delete', $fashionModel);

        if ($fashionModel->videoProjects()->exists() || VideoProject::where('before_fashion_model_id', $fashionModel->id)->exists()) {
            return back()->withErrors(['fashion_model' => 'Người mẫu đang được dùng trong dự án video, không xoá được.']);
        }

        $fashionModel->delete();
        Media::delete($fashionModel->photo_path);

        return back()->with('status', 'Đã xoá người mẫu.');
    }
}
