<?php

namespace App\Jobs;

use App\Enums\ProductImportStatus;
use App\Http\Controllers\ScriptController;
use App\Models\Product;
use App\Services\ProductImport\PageSnapshot;
use App\Services\ProductImport\ProductExtractor;
use App\Services\ProductImport\ProductImportException;
use App\Services\ProductImport\RemoteImages;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Tải ảnh và (khi có khoá Claude API) phân tích trang Shopee cho sản phẩm vừa tạo từ bookmarklet. */
class ImportProductJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    // Tải ảnh + Claude Code CLI (tối đa CLAUDE_CLI_TIMEOUT).
    public int $timeout = 420;

    public int $backoff = 30;

    public function __construct(public Product $product) {}

    public function handle(ProductExtractor $extractor): void
    {
        $product = $this->product;
        $snapshot = $product->import_snapshot ?? [];
        $page = new PageSnapshot($snapshot['url'] ?? '', $snapshot['title'] ?? '', $snapshot['text'] ?? '');

        try {
            // Lần thử lại không tải ảnh lần nữa.
            if ($product->images()->doesntExist()) {
                $product->addDownloadedImages(RemoteImages::download($snapshot['images'] ?? [], config('videoaff.product_images_max')));
            }

            if (ScriptController::webMode()) {
                $product->update(['import_status' => ProductImportStatus::AwaitingReply, 'import_error' => null]);

                return;
            }

            $product->applyImportedFields($extractor->extract($page));
        } catch (ProductImportException $e) {
            $this->fail($e);

            return;
        }

        $product->update(['import_status' => ProductImportStatus::Done, 'import_error' => null, 'import_snapshot' => null]);
    }

    public function failed(?Throwable $e): void
    {
        $this->product->update([
            'import_status' => ProductImportStatus::Failed,
            'import_error' => $e?->getMessage() ?? 'Lấy sản phẩm thất bại.',
        ]);
    }
}
