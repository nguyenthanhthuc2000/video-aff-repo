<?php

namespace App\Http\Controllers;

use App\Models\ProductImage;
use App\Models\Scene;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Đổi bộ đồ (sản phẩm, ảnh đúng màu, màu) của một cảnh. */
class SceneOutfitController extends Controller
{
    public function update(Request $request, Scene $scene): RedirectResponse
    {
        $project = $scene->videoProject;
        $this->authorize('update', $project);

        $validated = $request->validate([
            // null = sản phẩm của dự án.
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('user_id', $request->user()->id)->whereNotNull('images_approved_at')],
            'product_image_id' => ['nullable', 'integer'],
            'color' => ['nullable', 'string', 'max:80'],
        ], [], ['product_id' => 'sản phẩm', 'color' => 'màu']);

        $productId = $validated['product_id'] ?? null;
        $imageId = $validated['product_image_id'] ?? null;
        if ($imageId !== null && ! ProductImage::query()->whereKey($imageId)->where('product_id', $productId ?? $project->product_id)->exists()) {
            throw ValidationException::withMessages(['product_image_id' => 'Ảnh không thuộc sản phẩm đã chọn.']);
        }

        $scene->update([
            'product_id' => $productId === $project->product_id ? null : $productId,
            'product_image_id' => $imageId,
            'outfit_color' => filled($validated['color'] ?? null) ? trim($validated['color']) : null,
        ]);

        return back()->with('status', "Đã đổi bộ đồ cảnh {$scene->position}. Bấm Tạo lại ảnh để áp dụng.");
    }
}
