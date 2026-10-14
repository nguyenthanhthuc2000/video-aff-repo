<?php

namespace App\Services\MiniMax;

use App\Services\Image\ImageGeneratorException;
use App\Support\Media;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Gọi API MiniMax (platform.minimax.io): Bearer khoá, máy chủ theo vùng, lỗi trong base_resp.status_code.
 * Lỗi tạm (giới hạn tốc độ, mạng) thì thử lại; khoá sai / hết tiền / nội dung nhạy cảm thì báo rõ.
 */
class MiniMaxClient
{
    public static function baseUrl(): string
    {
        return config('videoaff.minimax.region') === 'china' ? 'https://api.minimaxi.com' : 'https://api.minimax.io';
    }

    /** @throws ImageGeneratorException */
    public static function post(string $path, array $body, int $timeout = 120): Response
    {
        return self::send(fn (PendingRequest $http) => $http->timeout($timeout)->post(self::baseUrl().$path, $body));
    }

    /** @throws ImageGeneratorException */
    public static function get(string $path, array $query = []): Response
    {
        return self::send(fn (PendingRequest $http) => $http->timeout(60)->get(self::baseUrl().$path, $query));
    }

    /** Ảnh trong kho media thành data URL (MiniMax nhận ảnh dạng Base64 Data URL). */
    public static function dataUrl(string $path): string
    {
        $contents = (string) Media::disk()->get($path);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: 'image/png';

        return "data:{$mime};base64,".base64_encode($contents);
    }

    /** @throws ImageGeneratorException */
    private static function send(callable $request): Response
    {
        $key = (string) config('videoaff.minimax.key');
        if ($key === '') {
            throw new ImageGeneratorException('Chưa có khoá MiniMax (trang Kết nối AI).');
        }
        try {
            $response = $request(Http::withToken($key)->acceptJson());
        } catch (ConnectionException $e) {
            throw ImageGeneratorException::retryable('Không kết nối được MiniMax.', $e);
        }
        $code = (int) ($response->json('base_resp.status_code') ?? 0);
        $message = (string) ($response->json('base_resp.status_msg') ?? '');
        if ($response->failed() || $code !== 0) {
            $text = match ($code) {
                1002 => 'MiniMax đang giới hạn tốc độ, thử lại sau.',
                1004, 2049 => 'Khoá MiniMax không hợp lệ.',
                1008 => 'Tài khoản MiniMax hết tiền.',
                1026, 1027 => 'MiniMax từ chối vì nội dung nhạy cảm: sửa mô tả shot rồi thử lại.',
                2013 => 'MiniMax báo tham số không hợp lệ: '.$message,
                default => 'MiniMax lỗi'.($code ? " {$code}" : ' HTTP '.$response->status()).($message ? ": {$message}" : '.'),
            };

            throw in_array($code, [1002], true) || $response->serverError() ? ImageGeneratorException::retryable($text) : new ImageGeneratorException($text);
        }

        return $response;
    }
}
