<?php

namespace App\Services\Audio;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Chép lời giọng nói (faster-whisper trong dịch vụ docker "audio", miễn phí): chữ kèm mốc thời gian và độ tin cậy. */
class Transcriber
{
    /**
     * @return array{text: string, duration_ms: int, words: list<array{start_ms: int, end_ms: int, word: string, probability: float}>, segments: list<array{start_ms: int, end_ms: int, text: string}>}
     *
     * @throws RuntimeException
     */
    public function transcribe(string $contents, string $language = 'en'): array
    {
        $config = config('videoaff.audio_analyzer');
        $http = Http::baseUrl(rtrim($config['url'], '/'))->timeout(180)
            ->when($config['token'], fn (PendingRequest $http, string $token) => $http->withToken($token));
        try {
            $response = $http->post('/transcribe', ['base64' => base64_encode($contents), 'language' => $language]);
        } catch (ConnectionException) {
            throw new RuntimeException('Không kết nối được dịch vụ nghe giọng (docker compose up -d audio).');
        }
        if ($response->failed()) {
            throw new RuntimeException('Chép lời lỗi: '.mb_substr((string) ($response->json('error') ?? $response->body()), 0, 300));
        }
        $ms = fn ($s) => (int) round((float) $s * 1000);

        return [
            'text' => (string) $response->json('text'),
            'duration_ms' => $ms($response->json('duration')),
            'words' => array_map(fn ($w) => ['start_ms' => $ms($w['start']), 'end_ms' => $ms($w['end']), 'word' => (string) $w['word'], 'probability' => (float) $w['probability']], (array) $response->json('words')),
            'segments' => array_map(fn ($s) => ['start_ms' => $ms($s['start']), 'end_ms' => $ms($s['end']), 'text' => (string) $s['text']], (array) $response->json('segments')),
        ];
    }
}
