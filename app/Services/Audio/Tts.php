<?php

namespace App\Services\Audio;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Đọc lời thoại bằng Kokoro TTS trong dịch vụ docker "audio" (miễn phí, chạy trên CPU của máy). */
class Tts
{
    /**
     * Đọc lần lượt các câu (mỗi câu một giọng), có khoảng lặng đầu / giữa / cuối.
     *
     * @param  list<array{text: string, voice: string, pitch: float, speed: float}>  $lines
     * @return array{contents: string, duration_ms: int, lines: list<array{start_ms: int, end_ms: int}>}
     *
     * @throws RuntimeException
     */
    public function speak(array $lines, float $lead = 0.3, float $gap = 0.35, float $tail = 0.4): array
    {
        $config = config('videoaff.audio_analyzer');
        $http = Http::baseUrl(rtrim($config['url'], '/'))->timeout(180)
            ->when($config['token'], fn (PendingRequest $http, string $token) => $http->withToken($token));

        try {
            $response = $http->post('/tts', ['lines' => $lines, 'lead' => $lead, 'gap' => $gap, 'tail' => $tail]);
        } catch (ConnectionException) {
            throw new RuntimeException('Không kết nối được dịch vụ giọng đọc (docker compose up -d audio).');
        }
        $contents = base64_decode((string) $response->json('base64'), true);
        if ($response->failed() || ! $contents) {
            throw new RuntimeException('Đọc lời thoại lỗi: '.mb_substr((string) ($response->json('error') ?? $response->body()), 0, 300));
        }

        return [
            'contents' => $contents,
            'duration_ms' => (int) round((float) $response->json('duration') * 1000),
            'lines' => array_map(fn ($l) => ['start_ms' => (int) round($l['start'] * 1000), 'end_ms' => (int) round($l['end'] * 1000)], (array) $response->json('lines')),
        ];
    }
}
