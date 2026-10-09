<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductRequest;
use App\Http\Resources\FashionModelResource;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Support\Media;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $products = $request->user()->products()->with('primaryImage')->latest()->get();

        return Inertia::render('products/index', [
            'products' => ProductResource::collection($products)->resolve(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('products/create');
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $product = DB::transaction(function () use ($request) {
            $product = $request->user()->products()->create($request->safe()->except('images'));
            $this->storeImages($product, $request->file('images', []));

            return $product;
        });

        return to_route('products.edit', $product)->with('status', 'Đã tạo sản phẩm.');
    }

    public function edit(Request $request, Product $product): Response
    {
        $this->authorize('update', $product);

        $product->load('images', 'primaryImage');

        return Inertia::render('products/edit', [
            'product' => ProductResource::make($product)->resolve(),
            'fashionModels' => FashionModelResource::collection(
                $request->user()->fashionModels()->whereNotNull('consent_at')->latest()->get()
            )->resolve(),
            'maxImages' => config('videoaff.product_images_max'),
        ]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);

        $maxNew = config('videoaff.product_images_max') - $product->images()->count();
        $newImages = $request->file('images', []);

        if (count($newImages) > $maxNew) {
            return back()->withErrors(['images' => "Chỉ được thêm tối đa {$maxNew} ảnh nữa."]);
        }

        DB::transaction(function () use ($request, $product, $newImages) {
            $product->update($request->safe()->except('images'));
            $this->storeImages($product, $newImages);
        });

        return back()->with('status', 'Đã lưu sản phẩm.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $this->authorize('delete', $product);

        $paths = $product->images()->pluck('path');
        $product->delete();
        $paths->each(fn ($path) => Media::delete($path));

        return to_route('products.index')->with('status', 'Đã xoá sản phẩm.');
    }

    /** @param  array<UploadedFile>  $files */
    private function storeImages(Product $product, array $files): void
    {
        $hasPrimary = $product->images()->where('is_primary', true)->exists();

        foreach ($files as $index => $file) {
            $product->images()->create([
                'path' => Media::store($file, "products/{$product->id}"),
                'is_primary' => ! $hasPrimary && $index === 0,
            ]);
        }
    }
}
