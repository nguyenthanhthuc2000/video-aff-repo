<?php

namespace App\Services\Audio;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Đọc bài hát tham khảo từ link YouTube qua dịch vụ "audio" (yt-dlp): thông tin video và phụ đề (lời kèm mốc thời gian).
 * Không tải âm thanh / video: bài tham khảo chỉ để Claude phân tích rồi sáng tác bài mới. Gửi rồi hỏi lại, không giữ hàng đợi.
 */
class YoutubeReference
{
    public const HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtu.be'];

    public static function isYoutube(string $url): bool
    {
        $parts = parse_url(trim($url));

        return in_array($parts['scheme'] ?? '', ['http', 'https'], true) && in_array(mb_strtolower($parts['host'] ?? ''), self::HOSTS, true);
    }

    /**
     * @return string mã yêu cầu
     *
     * @throws RuntimeException
     */
    public function start(string $url): string
    {
        $response = $this->call(fn (PendingRequest $http) => $http->post('/youtube', ['url' => $url, 'audio' => false]));

        return (string) ($response->json('id') ?? throw new RuntimeException('Dịch vụ audio không trả về mã yêu cầu.'));
    }

    /**
     * @return array{title: string, artist: string, channel: string, duration: float, description: string, tags: list<string>, language: ?string, captions_kind: ?string, captions: list<array{start: float, end: float, text: string}>}|null null nếu chưa xong
     *
     * @throws RuntimeException
     */
    public function poll(string $id): ?array
    {
        $data = $this->call(fn (PendingRequest $http) => $http->get('/youtube/'.rawurlencode($id)))->json();
        if (($data['state'] ?? null) === 'pending') {
            return null;
        }
        if (($data['state'] ?? null) !== 'done') {
            throw new RuntimeException('Đọc link YouTube lỗi: '.mb_substr((string) ($data['error'] ?? 'dữ liệu không hợp lệ'), 0, 300));
        }

        return [
            'title' => (string) ($data['title'] ?? ''),
            'artist' => (string) ($data['artist'] ?? ''),
            'channel' => (string) ($data['channel'] ?? ''),
            'duration' => (float) ($data['duration'] ?? 0),
            'description' => (string) ($data['description'] ?? ''),
            'tags' => array_values(array_map('strval', array_slice((array) ($data['tags'] ?? []), 0, 20))),
            'language' => filled($data['language'] ?? null) ? mb_substr((string) $data['language'], 0, 10) : null,
            'captions_kind' => $data['captions_kind'] ?? null,
            'captions' => array_values(array_map(fn ($c) => ['start' => (float) $c['start'], 'end' => (float) $c['end'], 'text' => (string) $c['text']], (array) ($data['captions'] ?? []))),
        ];
    }

    private function call(callable $send): Response
    {
        $config = config('videoaff.audio_analyzer');
        $http = Http::baseUrl(rtrim($config['url'], '/'))->timeout(60)
            ->when($config['token'], fn (PendingRequest $http, string $token) => $http->withToken($token));
        try {
            $response = $send($http);
        } catch (ConnectionException) {
            throw new RuntimeException('Không kết nối được dịch vụ audio (docker compose up -d audio).');
        }
        if ($response->failed()) {
            throw new RuntimeException('Đọc link YouTube lỗi: '.mb_substr((string) ($response->json('error') ?? $response->body()), 0, 300));
        }

        return $response;
    }
}
