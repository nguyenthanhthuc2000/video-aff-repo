<?php

namespace App\Services\ProductImport;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Tải ảnh sản phẩm từ CDN Shopee (chỉ host trong config videoaff.import_image_hosts). */
class RemoteImages
{
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    private const MAX_BYTES = 10 * 1024 * 1024;

    public static function allowed(string $url): bool
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['port'])) {
            return false;
        }

        $host = strtolower($parts['host']);
        foreach (config('videoaff.import_image_hosts') as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tải ảnh vào bộ nhớ trước khi lưu, để lỗi giữa chừng không để lại sản phẩm thiếu ảnh.
     *
     * Link trên trang Shopee có thể không phải ảnh (video, file khác) nên link hỏng được bỏ qua;
     * lấy tối đa $limit ảnh hợp lệ theo thứ tự.
     *
     * @param  list<string>  $urls
     * @return list<array{contents: string, extension: string}>
     *
     * @throws ProductImportException khi không tải được ảnh nào
     */
    public static function download(array $urls, int $limit): array
    {
        $images = [];
        $lastError = null;
        foreach ($urls as $url) {
            if (count($images) >= $limit) {
                break;
            }

            try {
                $images[] = self::fetch($url);
            } catch (ProductImportException $e) {
                $lastError = $e->getMessage();
                Log::warning('product_import.image_skipped', ['url' => $url, 'reason' => $lastError]);
            }
        }

        if ($urls !== [] && $images === []) {
            throw new ProductImportException('Không tải được ảnh nào từ Shopee ('.$lastError.').');
        }

        return $images;
    }

    /** @return array{contents: string, extension: string} */
    private static function fetch(string $url): array
    {
        if (! self::allowed($url)) {
            throw new ProductImportException('link không nằm trên CDN Shopee');
        }

        try {
            // Không theo redirect để không bị dẫn sang host ngoài danh sách cho phép.
            $response = Http::timeout(20)->withoutRedirecting()->get($url);
        } catch (ConnectionException) {
            throw new ProductImportException('lỗi mạng');
        }

        if (! $response->successful()) {
            throw new ProductImportException("HTTP {$response->status()}");
        }

        $contents = $response->body();
        if (strlen($contents) > self::MAX_BYTES) {
            throw new ProductImportException('ảnh lớn hơn 10MB');
        }

        // Nhận dạng theo nội dung file: CDN có thể trả Content-Type chung chung như application/octet-stream.
        $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: '';
        if (! isset(self::TYPES[$type])) {
            throw new ProductImportException("không phải ảnh ({$type})");
        }

        return ['contents' => $contents, 'extension' => self::TYPES[$type]];
    }
}
