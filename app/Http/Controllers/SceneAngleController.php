<?php

namespace App\Http\Controllers;

use App\Models\Scene;
use App\Support\ProductCoverage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Đổi góc quay sản phẩm của một cảnh (null = để AI tự chọn theo kịch bản). */
class SceneAngleController extends Controller
{
    public function update(Request $request, Scene $scene): RedirectResponse
    {
        $this->authorize('update', $scene->videoProject);

        $validated = $request->validate(['angle' => ['nullable', 'string', Rule::in(array_keys(ProductCoverage::ANGLES))]], [], ['angle' => 'góc quay']);
        $scene->update(['angle' => $validated['angle'] ?? null]);

        return back()->with('status', "Đã đổi góc quay cảnh {$scene->position}. Bấm Tạo lại ảnh (và clip) để áp dụng.");
    }
}
