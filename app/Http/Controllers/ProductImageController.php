<?php

namespace App\Http\Controllers;

use App\Models\ProductImage;
use App\Support\Media;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class ProductImageController extends Controller
{
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
