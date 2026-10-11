<?php

namespace App\Services\Audio;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Gọi service "audio": tách giọng hát (Demucs) rồi ghi lời kèm mốc thời gian (faster-whisper). Gửi rồi hỏi lại, không giữ hàng đợi. */
class VocalAnalyzer
{
    /**
     * @return string mã yêu cầu
     *
     * @throws RuntimeException
     */
    public function start(string $contents, string $name): string
    {
        $response = $this->call(fn (PendingRequest $http) => $http->post('/analyze', ['base64' => base64_encode($contents), 'name' => $name, 'language' => 'vi']));

        return (string) ($response->json('id') ?? throw new RuntimeException('Dịch vụ phân tích nhạc không trả về mã yêu cầu.'));
    }

    /**
     * @return array{duration: float, vocals: list<array{start: float, end: float}>, words: list<array{start: float, end: float, text: string}>}|null null nếu chưa xong
     *
     * @throws RuntimeException
     */
    public function poll(string $id): ?array
    {
        $data = $this->call(fn (PendingRequest $http) => $http->get('/analyze/'.rawurlencode($id)))->json();
        if (($data['state'] ?? null) === 'pending') {
            return null;
        }
        if (($data['state'] ?? null) !== 'done' || ! is_array($data['vocals'] ?? null) || ! is_array($data['words'] ?? null)) {
            throw new RuntimeException('Phân tích giọng hát lỗi: '.mb_substr((string) ($data['error'] ?? 'dữ liệu không hợp lệ'), 0, 300));
        }

        return [
            'duration' => (float) ($data['duration'] ?? 0),
            'vocals' => array_values(array_map(fn ($v) => ['start' => (float) $v['start'], 'end' => (float) $v['end']], $data['vocals'])),
            'words' => array_values(array_map(fn ($w) => ['start' => (float) $w['start'], 'end' => (float) $w['end'], 'text' => (string) $w['text']], $data['words'])),
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
            throw new RuntimeException('Không kết nối được dịch vụ phân tích nhạc (docker compose up -d audio).');
        }
        if ($response->failed()) {
            throw new RuntimeException('Phân tích giọng hát lỗi: '.mb_substr((string) ($response->json('error') ?? $response->body()), 0, 300));
        }

        return $response;
    }
}
