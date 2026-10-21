<?php

namespace App\Services\TopicShort;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Tìm ảnh trên wiki MediaWiki (Fandom, Wikimedia Commons) theo từ khoá, trong không gian tên File. */
class WikiImageSearch
{
    /** Wikimedia / Fandom chặn (403) User-Agent chung chung: cần tên ứng dụng kèm địa chỉ liên hệ. */
    public const USER_AGENT = 'VideoAff/1.0 (https://github.com/nguyenthanhthuc2000/video-aff-repo) Laravel-HTTP';

    /**
     * @return list<array{url: string, page: string, title: string, width: int, height: int}>
     *
     * @throws RuntimeException
     */
    public function search(string $wiki, string $query, ?int $limit = null): array
    {
        $api = config("videoaff.topic_shorts.wikis.{$wiki}.api");
        if (! $api || trim($query) === '') {
            return [];
        }
        $limit ??= (int) config('videoaff.topic_shorts.candidates');

        try {
            $response = Http::timeout(20)->acceptJson()->withUserAgent(self::USER_AGENT)->get($api, [
                'action' => 'query',
                'format' => 'json',
                'generator' => 'search',
                'gsrnamespace' => 6,
                'gsrsearch' => $query,
                'gsrlimit' => $limit * 2,
                'prop' => 'imageinfo',
                'iiprop' => 'url|size|mime',
                'iiurlwidth' => 1080,
            ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Không kết nối được wiki để tìm ảnh.');
        }
        if ($response->failed()) {
            throw new RuntimeException("Wiki trả lỗi khi tìm ảnh (HTTP {$response->status()}).");
        }

        $pages = collect((array) $response->json('query.pages'))->sortBy(fn ($page) => $page['index'] ?? 0);
        $results = [];
        foreach ($pages as $page) {
            $info = $page['imageinfo'][0] ?? null;
            if (! $info || ! in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) {
                continue;
            }
            $width = (int) ($info['width'] ?? 0);
            $height = (int) ($info['height'] ?? 0);
            // Bỏ biểu tượng, ảnh nhỏ: không đủ nét cho khung 1080×1920.
            if (min($width, $height) < 300) {
                continue;
            }
            $url = (string) ($info['thumburl'] ?? $info['url'] ?? '');
            if (! RemoteImage::allowed($url)) {
                continue;
            }
            $results[] = [
                'url' => $url,
                'page' => (string) ($info['descriptionurl'] ?? ''),
                'title' => (string) preg_replace('/^File:/', '', (string) ($page['title'] ?? '')),
                'width' => $width,
                'height' => $height,
            ];
            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }
}
