<?php

namespace App\Services\MiniMax;

use App\Services\Image\AsyncImageGenerator;
use App\Services\Image\GeneratedImage;
use App\Services\Image\ImageGeneratorException;
use App\Services\Image\MuseTask;
use Illuminate\Support\Facades\Cache;

/**
 * Ảnh nhân vật / khung hình bằng MiniMax image-01 (0,0035 USD / ảnh). API trả ngay nên gọi luôn ở start, poll lấy kết quả đã lưu.
 * image-01 chỉ nhận MỘT ảnh tham chiếu nhân vật (subject_reference): dùng ảnh đầu tiên (nhân vật chính của shot).
 * Khổ ảnh theo dòng khung trong prompt (9:16 dọc / 16:9 ngang).
 */
class MiniMaxImageGenerator implements AsyncImageGenerator
{
    public function start(array $referencePaths, string $prompt): string
    {
        $code = MuseTask::code();
        $response = MiniMaxClient::post('/v1/image_generation', self::payload($referencePaths, $prompt));
        $image = base64_decode((string) ($response->json('data.image_base64.0') ?? ''), true);
        if (! $image) {
            throw new ImageGeneratorException('MiniMax không trả về ảnh (có thể bị chặn vì nội dung).');
        }
        Cache::put("minimax-image:{$code}", base64_encode($image), now()->addHours(2));

        return $code;
    }

    public static function payload(array $referencePaths, string $prompt): array
    {
        return [
            'model' => config('videoaff.minimax.image_model'),
            'prompt' => mb_substr($prompt, 0, 1500),
            'aspect_ratio' => str_contains($prompt, '9:16') ? '9:16' : (str_contains($prompt, '16:9') ? '16:9' : '1:1'),
            'response_format' => 'base64',
            'n' => 1,
            ...($referencePaths !== [] ? ['subject_reference' => [['type' => 'character', 'image_file' => MiniMaxClient::dataUrl($referencePaths[0])]]] : []),
        ];
    }

    public function poll(string $code, array $referencePaths): ?GeneratedImage
    {
        $data = Cache::pull("minimax-image:{$code}");
        if ($data === null) {
            throw ImageGeneratorException::retryable('Không còn kết quả ảnh MiniMax, tạo lại.');
        }

        return new GeneratedImage(base64_decode($data), 'jpeg', 'minimax-image-01', $code, (float) config('videoaff.minimax.prices.image'));
    }
}
