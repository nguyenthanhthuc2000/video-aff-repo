<?php

namespace App\Services\Audio;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Lấy bài hát từ link YouTube qua dịch vụ "audio" (yt-dlp): thông tin video, phụ đề (làm lời theo thời gian) và file mp3.
 * Gửi rồi hỏi lại, không giữ hàng đợi. Chỉ nhận link YouTube; người dùng xác nhận có quyền với bài hát.
 */
class YoutubeSong
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
    public function start(string $url, int $maxBytes): string
    {
        $response = $this->call(fn (PendingRequest $http) => $http->post('/youtube', ['url' => $url, 'max_bytes' => $maxBytes]));

        return (string) ($response->json('id') ?? throw new RuntimeException('Dịch vụ audio không trả về mã yêu cầu.'));
    }

    /**
     * @return array{title: string, artist: string, channel: string, duration: float, description: string, language: ?string, captions_kind: ?string, captions: list<array{start: float, end: float, text: string}>}|null null nếu chưa xong
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
            throw new RuntimeException('Lấy bài hát từ YouTube lỗi: '.mb_substr((string) ($data['error'] ?? 'dữ liệu không hợp lệ'), 0, 300));
        }

        return [
            'title' => (string) ($data['title'] ?? ''),
            'artist' => (string) ($data['artist'] ?? ''),
            'channel' => (string) ($data['channel'] ?? ''),
            'duration' => (float) ($data['duration'] ?? 0),
            'description' => (string) ($data['description'] ?? ''),
            'language' => filled($data['language'] ?? null) ? mb_substr((string) $data['language'], 0, 10) : null,
            'captions_kind' => $data['captions_kind'] ?? null,
            'captions' => array_values(array_map(fn ($c) => ['start' => (float) $c['start'], 'end' => (float) $c['end'], 'text' => (string) $c['text']], (array) ($data['captions'] ?? []))),
        ];
    }

    /** @throws RuntimeException */
    public function audio(string $id): string
    {
        return $this->call(fn (PendingRequest $http) => $http->timeout(120)->get('/youtube/'.rawurlencode($id).'/audio'))->body();
    }

    /**
     * Phụ đề → "từ" có mốc như kết quả phân tích giọng hát (chia đều thời gian câu cho các chữ), để dùng chung cho lời theo thời gian.
     *
     * @param  list<array{start: float, end: float, text: string}>  $captions
     * @return list<array{start: float, end: float, text: string}>
     */
    public static function words(array $captions): array
    {
        $words = [];
        foreach ($captions as $cue) {
            $parts = preg_split('/\s+/u', trim($cue['text']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $step = ($cue['end'] - $cue['start']) / max(1, count($parts));
            foreach ($parts as $i => $part) {
                $words[] = ['start' => round($cue['start'] + $i * $step, 2), 'end' => round($cue['start'] + ($i + 1) * $step, 2), 'text' => $part];
            }
            // Mỗi câu phụ đề là một câu hát: đẩy câu sau ra để không bị gộp chung.
            if ($parts !== []) {
                $last = array_key_last($words);
                $words[$last]['end'] = round(max($words[$last]['start'], min($words[$last]['end'], $cue['end'] - 0.75)), 2);
            }
        }

        return $words;
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
            throw new RuntimeException('Lấy bài hát từ YouTube lỗi: '.mb_substr((string) ($response->json('error') ?? $response->body()), 0, 300));
        }

        return $response;
    }
}
