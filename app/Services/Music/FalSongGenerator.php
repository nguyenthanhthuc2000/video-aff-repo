<?php

namespace App\Services\Music;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Tạo bài hát (có giọng hát) qua hàng đợi của fal, trả phí theo lượt bằng FAL_KEY của người dùng. Ba model:
 * - minimax: MiniMax Music 3 (mặc định) — lời có thẻ đoạn + mô tả phong cách → bài hoàn chỉnh, giọng hát rõ lời, tới khoảng 5 phút.
 * - elevenlabs: ElevenLabs Music v2.5 — lời đưa vào kế hoạch từng đoạn (composition plan), chất lượng cao, đắt hơn.
 * - ace-step: ACE-Step — mã nguồn mở, rẻ nhất, tối đa 240 giây, giọng hát yếu hơn.
 * Chỉ gửi lời + phong cách của bài mới. Tên trường theo trang API của fal (2026-10); chưa chạy thử với tài khoản thật.
 */
class FalSongGenerator implements SongGenerator
{
    public const MODELS = [
        'minimax' => ['label' => 'MiniMax Music 3', 'endpoint' => 'minimax/music-3', 'max_seconds' => 300,
            'note' => 'Khuyên dùng: lời có thẻ [Verse] / [Chorus], giọng hát rõ lời, có bè và phối khí.'],
        'elevenlabs' => ['label' => 'ElevenLabs Music v2.5', 'endpoint' => 'elevenlabs/music/v2.5', 'max_seconds' => 600,
            'note' => 'Chất lượng cao nhất, điều khiển từng đoạn; đắt hơn (tính theo phút).'],
        'ace-step' => ['label' => 'ACE-Step', 'endpoint' => 'fal-ai/ace-step', 'max_seconds' => 240,
            'note' => 'Rẻ nhất, tối đa 4 phút; giọng hát thường yếu hơn.'],
    ];

    public function __construct(private readonly string $apiKey, private readonly string $model) {}

    public static function for(string $model): self
    {
        if (! isset(self::MODELS[$model])) {
            throw new RuntimeException("Model nhạc không hợp lệ: {$model}.");
        }

        return new self((string) config('videoaff.fal.key'), $model);
    }

    /** Ước tính chi phí (USD) theo giá niêm yết trong config (kiểm tra lại ở fal.ai/pricing). */
    public static function estimate(string $model, int $seconds): float
    {
        $price = config("videoaff.fal.music_prices.{$model}", []);
        $seconds = min($seconds, self::MODELS[$model]['max_seconds'] ?? $seconds);

        return round((float) ($price['per_song'] ?? 0) + (float) ($price['per_minute'] ?? 0) * $seconds / 60 + (float) ($price['per_second'] ?? 0) * $seconds, 3);
    }

    public function start(string $lyrics, string $style, int $seconds): string
    {
        $model = self::MODELS[$this->model];
        $seconds = max(30, min($model['max_seconds'], $seconds));
        $response = $this->send(fn (PendingRequest $http) => $http->post("https://queue.fal.run/{$model['endpoint']}", self::payload($this->model, $lyrics, $style, $seconds)));
        $id = (string) ($response->json('request_id') ?? throw new RuntimeException('fal không trả về mã yêu cầu.'));
        // Endpoint có đường dẫn con: dùng đúng link trạng thái / kết quả fal trả về.
        Cache::put("fal-music:{$id}", ['status' => $response->json('status_url'), 'response' => $response->json('response_url')], now()->addHours(3));

        return $id;
    }

    /** Dữ liệu gửi từng model. */
    public static function payload(string $model, string $lyrics, string $style, int $seconds): array
    {
        return match ($model) {
            'minimax' => ['prompt' => $style, 'lyrics' => self::minimaxLyrics($lyrics), 'duration' => $seconds],
            'elevenlabs' => ['composition_plan' => ['chunks' => self::chunks($lyrics, $style, $seconds)], 'output_format' => 'mp3_44100_192'],
            default => ['tags' => $style, 'lyrics' => $lyrics, 'duration' => $seconds],
        };
    }

    /** MiniMax: thẻ đoạn viết thường, mỗi thẻ một dòng ([Verse 1] → [verse]). */
    public static function minimaxLyrics(string $lyrics): string
    {
        $tags = ['intro', 'verse', 'pre-chorus', 'chorus', 'post-chorus', 'bridge', 'instrumental', 'solo', 'outro'];

        return implode("\n", array_map(function (string $line) use ($tags) {
            if (! preg_match('/^\s*\[\s*([^\]]+?)\s*\]\s*$/u', $line, $m)) {
                return rtrim($line);
            }
            $name = preg_replace('/\s*\d+$/', '', mb_strtolower(trim($m[1])));
            $name = str_replace(' ', '-', $name);

            return '['.(in_array($name, $tags, true) ? $name : 'verse').']';
        }, preg_split('/\R/u', trim($lyrics)) ?: []));
    }

    /**
     * ElevenLabs: mỗi đoạn lời thành một phần của kế hoạch (tên đoạn, tối đa 30 dòng × 200 ký tự), thời lượng chia theo số dòng.
     *
     * @return list<array{text: string, duration_ms: int, positive_styles: list<string>}>
     */
    public static function chunks(string $lyrics, string $style, int $seconds): array
    {
        $sections = [];
        foreach (preg_split('/\R/u', trim($lyrics)) ?: [] as $line) {
            $header = preg_match('/^\s*\[[^\]]+\]\s*$/u', $line) === 1;
            if ($header || $sections === []) {
                $sections[] = ['name' => $header ? trim($line) : '', 'lines' => []];
            }
            if (! $header && trim($line) !== '') {
                $sections[array_key_last($sections)]['lines'][] = mb_substr(trim($line), 0, 200);
            }
        }
        $sections = array_slice($sections, 0, 30);
        $weights = array_map(fn ($s) => max(2, count($s['lines'])), $sections);
        $styles = array_values(array_filter(array_map('trim', explode(',', $style)))) ?: ['pop'];

        return array_map(fn ($s, $w) => [
            'text' => trim($s['name']."\n".implode("\n", array_slice($s['lines'], 0, 30))),
            'duration_ms' => max(3000, min(120000, (int) round($seconds * 1000 * $w / array_sum($weights)))),
            'positive_styles' => $styles,
        ], $sections, $weights);
    }

    public function poll(string $id): ?array
    {
        $urls = Cache::get("fal-music:{$id}", []);
        $base = 'https://queue.fal.run/'.implode('/', array_slice(explode('/', self::MODELS[$this->model]['endpoint']), 0, 2))."/requests/{$id}";
        $status = (string) $this->send(fn (PendingRequest $http) => $http->get($urls['status'] ?? "{$base}/status"))->json('status');
        if (in_array($status, ['IN_QUEUE', 'IN_PROGRESS'], true)) {
            return null;
        }
        if ($status !== 'COMPLETED') {
            throw new RuntimeException("fal tạo nhạc lỗi (trạng thái {$status}).");
        }
        $url = $this->send(fn (PendingRequest $http) => $http->get($urls['response'] ?? $base))->json('audio.url');
        if (! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw new RuntimeException('fal không trả về file nhạc.');
        }
        try {
            $file = Http::timeout(180)->get($url);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Không tải được bài hát từ fal.', 0, $e);
        }
        if ($file->failed()) {
            throw new RuntimeException('Không tải được bài hát từ fal.');
        }
        Cache::forget("fal-music:{$id}");
        $type = (string) $file->header('Content-Type');

        return ['contents' => $file->body(), 'extension' => str_contains($type, 'wav') || str_ends_with(parse_url($url, PHP_URL_PATH) ?? '', '.wav') ? 'wav' : 'mp3'];
    }

    private function send(callable $request): Response
    {
        try {
            $response = $request(Http::withHeaders(['Authorization' => "Key {$this->apiKey}"])->acceptJson()->timeout(60));
        } catch (ConnectionException $e) {
            throw new RuntimeException('Không kết nối được fal.', 0, $e);
        }
        if ($response->failed()) {
            throw new RuntimeException('fal lỗi: '.mb_substr((string) json_encode($response->json('detail') ?? $response->body(), JSON_UNESCAPED_UNICODE), 0, 300));
        }

        return $response;
    }
}
