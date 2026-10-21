<?php

namespace App\Services\TopicShort;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Tải ảnh từ máy chủ ảnh wiki được phép (https, không chuyển hướng, giới hạn dung lượng, kiểm tra định dạng). */
class RemoteImage
{
    private const MAX_BYTES = 15 * 1024 * 1024;

    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public static function allowed(string $url): bool
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['port'])) {
            return false;
        }
        $host = strtolower($parts['host'] ?? '');

        foreach (config('videoaff.topic_shorts.image_hosts') as $allowed) {
            if ($host === $allowed || str_ends_with($host, ".{$allowed}")) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{contents: string, extension: string}
     *
     * @throws RuntimeException
     */
    public static function download(string $url): array
    {
        if (! self::allowed($url)) {
            throw new RuntimeException('Địa chỉ ảnh không thuộc nguồn được phép.');
        }
        try {
            $response = Http::timeout(30)->withoutRedirecting()->withHeaders(['User-Agent' => 'VideoAff/1.0 (topic shorts)'])->get($url);
        } catch (ConnectionException) {
            throw new RuntimeException('Không tải được ảnh.');
        }
        $contents = $response->body();
        if ($response->failed() || $contents === '' || strlen($contents) > self::MAX_BYTES) {
            throw new RuntimeException("Không tải được ảnh (HTTP {$response->status()}).");
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        if (! isset(self::TYPES[$mime])) {
            throw new RuntimeException('Tệp tải về không phải ảnh JPG / PNG / WEBP.');
        }

        return ['contents' => $contents, 'extension' => self::TYPES[$mime]];
    }
}
