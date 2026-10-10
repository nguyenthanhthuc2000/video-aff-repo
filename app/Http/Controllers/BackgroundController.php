<?php

namespace App\Http\Controllers;

use App\Models\Background;
use App\Models\Scene;
use App\Support\Media;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Menu Bối cảnh: ảnh nền của người dùng (phòng thay đồ, shop, góc nhà...) để AI đặt người mẫu vào. */
class BackgroundController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('backgrounds/index', [
            'backgrounds' => $request->user()->backgrounds()->withCount('scenes')->latest()->get()
                ->map(fn (Background $background) => [...$background->present(), 'scenes_count' => $background->scenes_count]),
        ]);
    }

    /** Thêm một hoặc nhiều ảnh (mỗi ảnh một bối cảnh). Không nhập tên thì đặt "Bối cảnh 1", "Bối cảnh 2"... */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'photos' => ['required', 'array', 'min:1', 'max:20'],
            'photos.*' => ['image', 'max:10240'],
        ], ['photos.required' => 'Chọn ít nhất 1 ảnh.'], ['name' => 'tên', 'description' => 'mô tả', 'photos' => 'ảnh', 'photos.*' => 'ảnh']);

        $photos = array_values($request->file('photos'));
        $name = trim((string) ($validated['name'] ?? ''));
        $next = $request->user()->backgrounds()->where('name', 'like', 'Bối cảnh %')->pluck('name')
            ->map(fn (string $name) => preg_match('/^Bối cảnh (\d+)$/u', $name, $m) ? (int) $m[1] : 0)->max() + 1;

        foreach ($photos as $i => $photo) {
            $request->user()->backgrounds()->create([
                'name' => match (true) {
                    $name === '' => 'Bối cảnh '.($next + $i),
                    count($photos) === 1 => $name,
                    default => "{$name} ".($i + 1),
                },
                'description' => $validated['description'] ?? null,
                'photo_path' => Media::store($photo, "backgrounds/{$request->user()->id}"),
            ]);
        }

        return back()->with('status', count($photos) === 1 ? 'Đã thêm bối cảnh.' : 'Đã thêm '.count($photos).' bối cảnh.');
    }

    public function update(Request $request, Background $background): RedirectResponse
    {
        $this->authorize('update', $background);

        $background->update($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
        ], [], ['name' => 'tên', 'description' => 'mô tả']));

        return back()->with('status', "Đã lưu bối cảnh {$background->name}.");
    }

    /** Xoá bối cảnh: các cảnh đang dùng chuyển về bối cảnh AI tự nghĩ. */
    public function destroy(Background $background): RedirectResponse
    {
        $this->authorize('delete', $background);

        $background->delete();
        Media::delete($background->photo_path);

        return back()->with('status', 'Đã xoá bối cảnh.');
    }

    /** Đổi bối cảnh của một cảnh (null = AI tự nghĩ theo prompt). Tạo lại ảnh để áp dụng. */
    public function scene(Request $request, Scene $scene): RedirectResponse
    {
        $this->authorize('update', $scene->videoProject);

        $validated = $request->validate([
            'background_id' => ['nullable', Rule::exists('backgrounds', 'id')->where('user_id', $request->user()->id)],
        ], [], ['background_id' => 'bối cảnh']);
        $scene->update(['background_id' => $validated['background_id'] ?? null]);

        return back()->with('status', "Đã đổi bối cảnh cảnh {$scene->position}. Bấm Tạo lại ảnh để áp dụng.");
    }
}
