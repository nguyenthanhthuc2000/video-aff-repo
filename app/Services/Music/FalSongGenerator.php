<?php

namespace App\Services\Music;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Tạo bài hát qua hàng đợi của fal (trả phí theo lượt, cần FAL_KEY). Model mặc định ACE-Step (lời + thẻ phong cách → bài có giọng hát),
 * đổi bằng FAL_MUSIC_ENDPOINT. Chưa chạy thử với tài khoản thật: kiểm tra tên trường của model trên trang fal trước khi dùng.
 */
class FalSongGenerator implements SongGenerator
{
    public function __construct(private readonly string $apiKey, private readonly string $endpoint) {}

    public static function fromConfig(): self
    {
        return new self((string) config('videoaff.fal.key'), (string) config('videoaff.fal.music_endpoint'));
    }

    public function start(string $lyrics, string $style, int $seconds): string
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post("https://queue.fal.run/{$this->endpoint}", [
            'tags' => $style,
            'lyrics' => $lyrics,
            'duration' => max(30, min(240, $seconds)),
        ]));

        return (string) ($response->json('request_id') ?? throw new RuntimeException('fal không trả về mã yêu cầu.'));
    }

    public function poll(string $id): ?array
    {
        $base = "https://queue.fal.run/{$this->endpoint}/requests/{$id}";
        $status = (string) $this->send(fn (PendingRequest $http) => $http->get("{$base}/status"))->json('status');
        if (in_array($status, ['IN_QUEUE', 'IN_PROGRESS'], true)) {
            return null;
        }
        if ($status !== 'COMPLETED') {
            throw new RuntimeException("fal tạo nhạc lỗi (trạng thái {$status}).");
        }
        $url = $this->send(fn (PendingRequest $http) => $http->get($base))->json('audio.url');
        if (! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw new RuntimeException('fal không trả về file nhạc.');
        }
        try {
            $file = Http::timeout(120)->get($url);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Không tải được bài hát từ fal.', 0, $e);
        }
        $extension = str_contains((string) $file->header('Content-Type'), 'wav') ? 'wav' : 'mp3';

        return ['contents' => $file->body(), 'extension' => $extension];
    }

    private function send(callable $request): Response
    {
        try {
            $response = $request(Http::withHeaders(['Authorization' => "Key {$this->apiKey}"])->acceptJson()->timeout(60));
        } catch (ConnectionException $e) {
            throw new RuntimeException('Không kết nối được fal.', 0, $e);
        }
        if ($response->failed()) {
            throw new RuntimeException('fal lỗi: '.mb_substr((string) ($response->json('detail') ?? $response->body()), 0, 300));
        }

        return $response;
    }
}
