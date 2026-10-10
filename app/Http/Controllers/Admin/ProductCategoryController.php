<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MotionStyle;
use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Master data loại sản phẩm: từ khoá nhận diện, bối cảnh, kiểu chuyển động, cách dùng sản phẩm trong ảnh. */
class ProductCategoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/product-categories', [
            'categories' => ProductCategory::query()->orderBy('sort_order')->orderBy('id')->get()->map(fn (ProductCategory $category) => [
                'id' => $category->id,
                'parent_id' => $category->parent_id,
                'key' => $category->key,
                'name' => $category->name,
                'keywords' => $category->keywords ?? [],
                'scene_settings' => $category->scene_settings ?? [],
                'motion_styles' => $category->motion_styles ?? [],
                'usage' => $category->usage,
                'sort_order' => $category->sort_order,
            ]),
            'motionStyles' => array_map(fn (MotionStyle $style) => ['value' => $style->value, 'label' => $style->label()], MotionStyle::cases()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        ProductCategory::create($this->validated($request));

        return back()->with('status', 'Đã thêm loại sản phẩm.');
    }

    public function update(Request $request, ProductCategory $productCategory): RedirectResponse
    {
        $productCategory->update($this->validated($request, $productCategory));

        return back()->with('status', "Đã lưu \"{$productCategory->name}\".");
    }

    public function destroy(ProductCategory $productCategory): RedirectResponse
    {
        // "Khác" là loại dự phòng khi không nhận ra sản phẩm.
        if ($productCategory->key === ProductCategory::OTHER) {
            return back()->with('status', 'Không xoá được loại "Khác" (dùng khi không nhận ra sản phẩm).');
        }

        $productCategory->delete();

        return back()->with('status', "Đã xoá \"{$productCategory->name}\" (kèm các loại phụ).");
    }

    /** Danh sách nhập theo dòng ở giao diện: mỗi dòng một từ khoá / bối cảnh. */
    private function validated(Request $request, ?ProductCategory $category = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'key' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/', Rule::unique('product_categories', 'key')->ignore($category?->id)],
            // Chỉ 2 cấp: loại phụ thuộc một loại chính.
            'parent_id' => ['nullable', Rule::exists('product_categories', 'id')->whereNull('parent_id'), Rule::notIn(array_filter([$category?->id]))],
            'keywords' => ['array', 'max:100'],
            'keywords.*' => ['string', 'max:60'],
            'scene_settings' => ['array', 'max:30'],
            'scene_settings.*' => ['string', 'max:200'],
            'motion_styles' => ['array'],
            'motion_styles.*' => ['distinct', Rule::enum(MotionStyle::class)],
            'usage' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ], ['key.regex' => 'Mã chỉ gồm chữ thường không dấu, số và dấu gạch dưới.'], [
            'name' => 'tên', 'key' => 'mã', 'parent_id' => 'loại chính', 'keywords' => 'từ khoá', 'scene_settings' => 'bối cảnh', 'usage' => 'cách dùng',
        ]);

        $clean = fn (array $items) => array_values(array_unique(array_filter(array_map('trim', $items), fn ($item) => $item !== '')));

        return [
            ...$validated,
            'keywords' => $clean(array_map('mb_strtolower', $validated['keywords'] ?? [])),
            'scene_settings' => $clean($validated['scene_settings'] ?? []) ?: null,
            'motion_styles' => $clean($validated['motion_styles'] ?? []) ?: null,
            'usage' => filled($validated['usage'] ?? null) ? $validated['usage'] : null,
            'sort_order' => $validated['sort_order'] ?? 0,
        ];
    }
}
