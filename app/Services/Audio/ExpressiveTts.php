<?php

namespace App\Services\Audio;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Đọc lời thoại biểu cảm bằng Chatterbox trong dịch vụ docker "expressive" (miễn phí, chậm: gửi rồi hỏi lại). */
class ExpressiveTts
{
    /**
     * @param  list<array{text: string, ref: string, exaggeration: float, cfg_weight: float}>  $lines  ref: WAV giọng mẫu (nội dung file).
     *
     * @throws RuntimeException
     */
    public function start(array $lines, float $lead, float $gap, float $tail): string
    {
        $response = $this->call(fn (PendingRequest $http) => $http->post('/tts', [
            'lines' => array_map(fn ($line) => [...$line, 'ref' => base64_encode($line['ref'])], $lines),
            'lead' => $lead, 'gap' => $gap, 'tail' => $tail,
        ]));

        return (string) $response->json('id');
    }

    /**
     * Đổi giọng từng đoạn nói của tiếng nguồn sang giọng mẫu, giữ nguyên vị trí (khớp khẩu hình).
     *
     * @param  list<array{start: float, end: float, ref: string}>  $segments  ref: WAV giọng mẫu (nội dung file).
     *
     * @throws RuntimeException
     */
    public function convert(string $sourceWav, array $segments): string
    {
        $response = $this->call(fn (PendingRequest $http) => $http->timeout(120)->post('/vc', [
            'source' => base64_encode($sourceWav),
            'segments' => array_map(fn ($s) => [...$s, 'ref' => base64_encode($s['ref'])], $segments),
        ]));

        return (string) $response->json('id');
    }

    /**
     * @return array{contents: string, duration_ms: int, lines: list<array{start_ms: int, end_ms: int}>}|false|null
     *                                                                                                              null = đang đọc; false = dịch vụ không còn yêu cầu này (đã khởi động lại), gửi lại.
     *
     * @throws RuntimeException
     */
    public function poll(string $id): array|false|null
    {
        $response = $this->call(fn (PendingRequest $http) => $http->get("/tts/{$id}"), allow404: true);
        if ($response->status() === 404) {
            return false;
        }

        return match ($response->json('state')) {
            'pending' => null,
            'done' => [
                'contents' => (string) base64_decode((string) $response->json('base64'), true),
                'duration_ms' => (int) round((float) $response->json('duration') * 1000),
                'lines' => array_map(fn ($l) => ['start_ms' => (int) round($l['start'] * 1000), 'end_ms' => (int) round($l['end'] * 1000)], (array) $response->json('lines')),
            ],
            default => throw new RuntimeException('Đọc biểu cảm lỗi: '.mb_substr((string) $response->json('error'), 0, 300)),
        };
    }

    private function call(callable $send, bool $allow404 = false): Response
    {
        $config = config('videoaff.expressive_tts');
        $http = Http::baseUrl(rtrim($config['url'], '/'))->timeout(60)
            ->when($config['token'], fn (PendingRequest $http, string $token) => $http->withToken($token));
        try {
            $response = $send($http);
        } catch (ConnectionException) {
            throw new RuntimeException('Không kết nối được dịch vụ giọng biểu cảm (docker compose up -d expressive; Docker cần khoảng 10–12 GB RAM).');
        }
        if ($response->failed() && ! ($allow404 && $response->status() === 404)) {
            throw new RuntimeException('Đọc biểu cảm lỗi: '.mb_substr((string) ($response->json('error') ?? $response->body()), 0, 300));
        }

        return $response;
    }
}
