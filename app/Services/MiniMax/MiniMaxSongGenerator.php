<?php

namespace App\Services\MiniMax;

use App\Services\Image\ImageGeneratorException;
use App\Services\Music\SongGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Bài hát bằng MiniMax Music trực tiếp (music-3.0, 0,15 USD / bài tới 5 phút). Lưu ý: từ 20/08/2026 MiniMax không mở API nhạc trả phí cho
 * người dùng mới; tài khoản mới dùng MiniMax Music 3 qua fal. API trả bài ngay (có thể mất vài phút) nên gọi ở start, poll lấy kết quả.
 */
class MiniMaxSongGenerator implements SongGenerator
{
    public function start(string $lyrics, string $style, int $seconds): string
    {
        try {
            $response = MiniMaxClient::post('/v1/music_generation', self::payload($lyrics, $style), 600);
        } catch (ImageGeneratorException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
        $url = $response->json('data.audio');
        if (! is_string($url) || ! str_starts_with($url, 'http')) {
            throw new RuntimeException('MiniMax không trả về bài hát.');
        }
        $id = 'mm-'.bin2hex(random_bytes(6));
        Cache::put("minimax-song:{$id}", $url, now()->addHours(20));

        return $id;
    }

    public static function payload(string $lyrics, string $style): array
    {
        return [
            'model' => config('videoaff.minimax.music_model'),
            'prompt' => mb_substr($style, 0, 2000),
            'lyrics' => mb_substr($lyrics, 0, 3500),
            'output_format' => 'url',
            'audio_setting' => ['sample_rate' => 44100, 'bitrate' => 256000, 'format' => 'mp3'],
        ];
    }

    public function poll(string $id): ?array
    {
        $url = Cache::pull("minimax-song:{$id}") ?? throw new RuntimeException('Không còn kết quả bài hát MiniMax.');
        $file = Http::timeout(180)->get($url);
        if ($file->failed()) {
            throw new RuntimeException('Không tải được bài hát từ MiniMax.');
        }

        return ['contents' => $file->body(), 'extension' => 'mp3'];
    }
}
