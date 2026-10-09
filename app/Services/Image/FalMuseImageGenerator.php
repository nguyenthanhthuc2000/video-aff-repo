<?php

namespace App\Services\Image;

use App\Support\Media;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Meta Muse Image qua fal (endpoint meta/muse-image/edit).
 * Input: prompt, image_urls (1–10, nhận data URL), aspect_ratio, output_format. Output: images[].url.
 */
class FalMuseImageGenerator implements ImageGenerator
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $endpoint,
        private readonly float $costPerImage,
        private readonly int $timeout = 240,
    ) {}

    public function generate(array $referencePaths, string $prompt): GeneratedImage
    {
        $imageUrls = array_map($this->dataUrl(...), array_slice($referencePaths, 0, 10));

        try {
            $response = Http::withHeaders(['Authorization' => 'Key '.$this->apiKey])
                ->timeout($this->timeout)
                ->acceptJson()
                ->post('https://fal.run/'.$this->endpoint, [
                    'prompt' => $prompt,
                    'image_urls' => $imageUrls,
                    'aspect_ratio' => '9:16',
                    'num_images' => 1,
                    'output_format' => 'jpeg',
                ]);
        } catch (ConnectionException $e) {
            throw ImageGeneratorException::retryable('Không kết nối được fal.', $e);
        }

        if ($response->failed()) {
            throw $this->errorFor($response);
        }

        $url = $response->json('images.0.url');
        if (! is_string($url)) {
            throw new ImageGeneratorException('fal không trả về ảnh.');
        }

        $contents = str_starts_with($url, 'data:')
            ? base64_decode(substr($url, strpos($url, ',') + 1))
            : $this->download($url);

        $requestId = $response->header('x-fal-request-id') ?: $response->json('request_id');

        Log::info('fal.muse_image', ['endpoint' => $this->endpoint, 'request_id' => $requestId, 'cost_usd' => $this->costPerImage]);

        return new GeneratedImage(
            contents: $contents,
            extension: 'jpg',
            provider: 'fal:'.$this->endpoint,
            requestId: $requestId ?: null,
            costUsd: $this->costPerImage,
        );
    }

    private function dataUrl(string $path): string
    {
        $contents = Media::disk()->get($path);
        if ($contents === null) {
            throw new ImageGeneratorException("Không đọc được ảnh tham chiếu {$path}.");
        }

        $mime = Media::disk()->mimeType($path) ?: 'image/jpeg';

        return "data:{$mime};base64,".base64_encode($contents);
    }

    private function download(string $url): string
    {
        try {
            $response = Http::timeout(60)->get($url);
        } catch (ConnectionException $e) {
            throw ImageGeneratorException::retryable('Không tải được ảnh từ fal.', $e);
        }

        if ($response->failed()) {
            throw ImageGeneratorException::retryable("Không tải được ảnh từ fal (HTTP {$response->status()}).");
        }

        return $response->body();
    }

    private function errorFor(Response $response): ImageGeneratorException
    {
        $status = $response->status();
        $detail = $response->json('detail');
        $detail = is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE);

        return match (true) {
            $status === 401, $status === 403 => new ImageGeneratorException('Khoá fal không hợp lệ (kiểm tra FAL_KEY).'),
            $status === 429, $status >= 500 => ImageGeneratorException::retryable("fal đang bận hoặc lỗi tạm thời (HTTP {$status})."),
            default => new ImageGeneratorException("fal từ chối yêu cầu (HTTP {$status}): ".mb_substr((string) $detail, 0, 300)),
        };
    }
}
