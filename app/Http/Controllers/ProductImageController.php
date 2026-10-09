<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductImage;
use App\Support\Media;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductImageController extends Controller
{
    /** Duyệt ảnh lấy từ Shopee: giữ ảnh đã chọn, xoá phần còn lại. */
    public function approve(Request $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);

        $ids = $product->images()->pluck('id')->all();
        $validated = $request->validate([
            'image_ids' => ['required', 'array', 'min:1'],
            'image_ids.*' => ['integer', 'distinct', Rule::in($ids)],
            'primary_id' => ['required', 'integer', Rule::in($request->input('image_ids', []))],
        ], [
            'image_ids.required' => 'Chọn ít nhất 1 ảnh.',
            'primary_id.in' => 'Ảnh chính phải nằm trong các ảnh đã chọn.',
        ], ['image_ids' => 'ảnh', 'primary_id' => 'ảnh chính']);

        $product->approveImages(array_map(intval(...), $validated['image_ids']), (int) $validated['primary_id']);

        return back()->with('status', 'Đã duyệt '.count($validated['image_ids']).' ảnh. Giờ có thể tạo video.');
    }

    /** Đánh dấu ảnh chính (ảnh nền trắng dùng để AI mặc lên người mẫu). */
    public function makePrimary(ProductImage $image): RedirectResponse
    {
        $this->authorize('update', $image->product);

        DB::transaction(function () use ($image) {
            $image->product->images()->update(['is_primary' => false]);
            $image->update(['is_primary' => true]);
        });

        return back();
    }

    public function destroy(ProductImage $image): RedirectResponse
    {
        $product = $image->product;
        $this->authorize('update', $product);

        if ($product->images()->count() === 1) {
            return back()->withErrors(['images' => 'Sản phẩm cần ít nhất 1 ảnh.']);
        }

        DB::transaction(function () use ($image, $product) {
            $image->delete();

            if ($image->is_primary) {
                $product->images()->oldest('id')->first()?->update(['is_primary' => true]);
            }
        });

        Media::delete($image->path);

        return back();
    }
}
