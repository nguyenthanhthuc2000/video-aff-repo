<?php

namespace App\Http\Controllers;

use App\Enums\ProductImportStatus;
use App\Jobs\ImportProductJob;
use App\Models\Product;
use App\Services\ProductImport\ProductExtractPrompt;
use App\Services\ProductImport\ProductImportException;
use App\Services\ProductImport\RemoteImages;
use App\Support\CreditPricing;
use App\Support\Credits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductImportController extends Controller
{
    /**
     * Tạo sản phẩm ngay từ dữ liệu bookmarklet gửi về (tên, giá từ JSON-LD hoặc tiêu đề tab),
     * rồi để job nền tải ảnh và cho Claude phân tích phần còn lại.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'title' => ['nullable', 'string', 'max:500'],
            'text' => ['required', 'string', 'max:60000'],
            'name' => ['nullable', 'string', 'max:500'],
            'price' => ['nullable', 'integer', 'min:0'],
            'images' => ['required', 'array', 'min:1', 'max:30'],
            'images.*' => ['string', 'max:2048'],
        ], [
            'images.required' => 'Không thấy ảnh sản phẩm trên trang. Đợi trang tải xong rồi bấm bookmark lại.',
        ], ['text' => 'nội dung trang', 'images' => 'ảnh']);

        $images = array_values(array_filter($validated['images'], RemoteImages::allowed(...)));
        if ($images === []) {
            throw ValidationException::withMessages(['images' => 'Không có ảnh nào trên CDN Shopee.']);
        }

        $title = trim(preg_replace('/\s*[|\-–]\s*Shopee.*$/iu', '', $validated['title'] ?? '') ?? '');
        $product = $request->user()->products()->create([
            'name' => mb_substr($validated['name'] ?: ($title ?: 'Sản phẩm Shopee'), 0, 255),
            'shopee_url' => $validated['url'],
            'price' => $validated['price'] ?? null,
            'import_status' => ProductImportStatus::Importing,
            'import_snapshot' => [
                'url' => $validated['url'],
                'title' => $validated['title'] ?? '',
                'text' => $validated['text'],
                'images' => $images,
            ],
        ]);

        ImportProductJob::dispatch($product);

        return to_route('products.edit', $product)->with('status', 'Đã thêm sản phẩm. Đang tải ảnh và phân tích trang...');
    }

    /** Chế độ Claude bản web: nhận câu trả lời JSON dán từ claude.ai. */
    public function reply(Request $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);

        $validated = $request->validate([
            'reply' => ['required', 'string', 'max:20000'],
        ], [], ['reply' => 'câu trả lời của AI']);

        try {
            $product->applyImportedFields(ProductExtractPrompt::parseWebReply($validated['reply']));
        } catch (ProductImportException $e) {
            throw ValidationException::withMessages(['reply' => $e->getMessage()]);
        }

        $product->update(['import_status' => ProductImportStatus::Done, 'import_error' => null, 'import_snapshot' => null]);

        return back()->with('status', 'Đã điền thông tin sản phẩm.');
    }

    /** Thử lại khi job lấy sản phẩm bị lỗi. */
    public function retry(Product $product): RedirectResponse
    {
        $this->authorize('update', $product);

        if ($product->import_status !== ProductImportStatus::Failed || $product->import_snapshot === null) {
            return back();
        }

        if (! ScriptController::webMode() && ($message = Credits::shortfall($product->user, CreditPricing::aiEstimates()['product_import'], 'đọc thông tin sản phẩm'))) {
            return back()->with('status', $message);
        }

        $product->update(['import_status' => ProductImportStatus::Importing, 'import_error' => null]);
        ImportProductJob::dispatch($product);

        return back()->with('status', 'Đang thử lại...');
    }
}
