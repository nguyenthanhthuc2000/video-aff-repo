<?php

namespace App\Services\Manga;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Tải ảnh từ một địa chỉ bất kỳ trên mạng (Claude tìm được): chỉ http(s) tới máy chủ công khai (chặn mạng nội bộ),
 * tự theo tối đa 3 lần chuyển hướng (kiểm tra lại từng lần), giới hạn dung lượng, phải là ảnh JPG / PNG / WEBP đủ lớn.
 */
final class WebImage
{
    private const MAX_BYTES = 15 * 1024 * 1024;

    private const MIN_SIDE = 300;

    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /** Đổi tên máy chủ thành IP (test thay bằng hàm giả, không cần DNS). @var (Closure(string): list<string>)|null */
    public static ?Closure $resolver = null;

    public const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';

    public static function safe(string $url): bool
    {
        $parts = parse_url($url);
        if (! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || isset($parts['user']) || empty($parts['host'])) {
            return false;
        }
        if (isset($parts['port']) && ! in_array((int) $parts['port'], [80, 443], true)) {
            return false;
        }
        $host = strtolower(trim($parts['host'], '[]'));
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (self::$resolver ? (self::$resolver)($host) : (gethostbynamel($host) ?: []));

        return $ips !== [] && collect($ips)->every(fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false);
    }

    /**
     * @return array{contents: string, extension: string, width: int, height: int}
     *
     * @throws RuntimeException
     */
    public static function download(string $url): array
    {
        for ($hop = 0; $hop <= 3; $hop++) {
            if (! self::safe($url)) {
                throw new RuntimeException('Địa chỉ ảnh không hợp lệ.');
            }
            try {
                $response = Http::timeout(30)->withoutRedirecting()->withUserAgent(self::USER_AGENT)->accept('image/*')->get($url);
            } catch (ConnectionException) {
                throw new RuntimeException('Không tải được ảnh.');
            }
            if (! $response->redirect()) {
                break;
            }
            $location = (string) $response->header('Location');
            $url = str_starts_with($location, 'http') ? $location : (parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST).'/'.ltrim($location, '/'));
        }
        $contents = $response->body();
        if ($response->failed() || $contents === '' || strlen($contents) > self::MAX_BYTES) {
            throw new RuntimeException("Không tải được ảnh (HTTP {$response->status()}).");
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        $size = @getimagesizefromstring($contents);
        if (! isset(self::TYPES[$mime]) || ! $size) {
            throw new RuntimeException('Tệp tải về không phải ảnh JPG / PNG / WEBP.');
        }
        if (min($size[0], $size[1]) < self::MIN_SIDE) {
            throw new RuntimeException('Ảnh quá nhỏ.');
        }

        return ['contents' => $contents, 'extension' => self::TYPES[$mime], 'width' => $size[0], 'height' => $size[1]];
    }
}
