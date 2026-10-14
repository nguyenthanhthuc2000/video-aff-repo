<?php

namespace App\Services\MiniMax;

use App\Services\Image\ImageGeneratorException;
use App\Services\Video\ClipGenerator;
use Illuminate\Support\Facades\Http;

/**
 * Clip từ khung hình bằng Hailuo (MiniMax image-to-video): 6 hoặc 10 giây, 768P mặc định, khổ theo ảnh đầu.
 * Hailuo không nhận file tiếng: không hát nhép theo đúng đoạn nhạc được như Muse (miệng chỉ mấp máy theo mô tả).
 */
class MiniMaxClipGenerator implements ClipGenerator
{
    public function start(string $imagePath, string $prompt, ?string $referenceVideoPath = null, ?string $audioPath = null): string
    {
        $response = MiniMaxClient::post('/v1/video_generation', self::payload($imagePath, $prompt));

        return (string) ($response->json('task_id') ?? throw new ImageGeneratorException('MiniMax không trả về mã yêu cầu.'));
    }

    public static function seconds(string $prompt): int
    {
        return preg_match('/(\d+)-second/', $prompt, $m) && (int) $m[1] > 6 ? 10 : 6;
    }

    public static function payload(string $imagePath, string $prompt): array
    {
        return [
            'model' => config('videoaff.minimax.video_model'),
            'first_frame_image' => MiniMaxClient::dataUrl($imagePath),
            'prompt' => mb_substr($prompt, 0, 2000),
            'duration' => self::seconds($prompt),
            'resolution' => config('videoaff.minimax.video_resolution'),
        ];
    }

    /** Giá ước tính một clip (USD). */
    public static function price(int $seconds = 6): float
    {
        $prices = config('videoaff.minimax.prices.video.'.config('videoaff.minimax.video_model'), [6 => 0.28, 10 => 0.56]);

        return (float) ($prices[$seconds > 6 ? 10 : 6] ?? 0.28);
    }

    public function poll(string $code): ?array
    {
        $task = MiniMaxClient::get('/v1/query/video_generation', ['task_id' => $code]);
        $status = (string) $task->json('status');
        if (in_array($status, ['Preparing', 'Queueing', 'Processing', ''], true)) {
            return null;
        }
        if ($status !== 'Success') {
            throw new ImageGeneratorException('Hailuo làm clip thất bại.');
        }
        $file = MiniMaxClient::get('/v1/files/retrieve', ['file_id' => $task->json('file_id')]);
        $url = $file->json('file.download_url') ?? $file->json('download_url');
        if (! is_string($url) || $url === '') {
            throw new ImageGeneratorException('MiniMax không trả về link tải clip.');
        }
        $video = Http::timeout(180)->get(str_starts_with($url, 'http') ? $url : "https://{$url}");
        if ($video->failed() || $video->body() === '') {
            throw ImageGeneratorException::retryable('Không tải được clip từ MiniMax.');
        }

        return ['contents' => $video->body(), 'extension' => 'mp4'];
    }
}
