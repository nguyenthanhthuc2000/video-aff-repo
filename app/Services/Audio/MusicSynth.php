<?php

namespace App\Services\Audio;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Phát nhạc Claude sáng tác (giai điệu + hợp âm) thành WAV trong dịch vụ docker "audio" (FluidSynth, miễn phí). */
class MusicSynth
{
    /**
     * @param  list<array{cue: array, seconds: float}>  $cues
     * @return list<string> Nội dung WAV của từng cue, đúng thứ tự.
     *
     * @throws RuntimeException
     */
    public function render(array $cues): array
    {
        $config = config('videoaff.audio_analyzer');
        $http = Http::baseUrl(rtrim($config['url'], '/'))->timeout(300)
            ->when($config['token'], fn (PendingRequest $http, string $token) => $http->withToken($token));
        try {
            $response = $http->post('/music', ['cues' => $cues]);
        } catch (ConnectionException) {
            throw new RuntimeException('Không kết nối được dịch vụ âm thanh (docker compose up -d audio).');
        }
        if ($response->failed()) {
            throw new RuntimeException('Phát nhạc lỗi: '.mb_substr((string) ($response->json('error') ?? $response->body()), 0, 300));
        }

        return array_map(fn ($c) => (string) base64_decode((string) $c['base64'], true), (array) $response->json('cues'));
    }
}
