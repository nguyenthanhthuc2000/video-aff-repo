<?php

namespace App\Services\Audio;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Tải file nhạc từ link người dùng nhập. Chống truy cập mạng nội bộ (SSRF): chỉ http/https, mọi IP của tên miền phải là IP công khai,
 * kết nối được ghim vào IP đã kiểm tra, tự theo chuyển hướng (kiểm tra lại từng bước), giới hạn dung lượng và chỉ nhận file âm thanh.
 * Link trang web (TikTok, YouTube...) không phải file nhạc thì trả null: người dùng vẫn lưu được link để tham khảo.
 */
class RemoteAudio
{
    private const EXTENSIONS = [
        'audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3', 'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'audio/aac' => 'aac',
        'audio/x-hx-aac-adts' => 'aac', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/ogg' => 'ogg', 'application/ogg' => 'ogg',
    ];

    /** Thay trong test (không phụ thuộc DNS thật). @var (Closure(string): list<string>)|null */
    public static ?Closure $resolver = null;

    /**
     * @return array{contents: string, mime: string, extension: string}|null
     *
     * @throws RemoteAudioException link không hợp lệ / bị chặn / quá lớn
     */
    public static function download(string $url, int $maxBytes): ?array
    {
        for ($hop = 0; $hop < 4; $hop++) {
            [$host, $port, $ip] = self::checkUrl($url);

            try {
                $response = Http::timeout(30)
                    ->withOptions([
                        'allow_redirects' => false,
                        'stream' => true,
                        'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]],
                    ])
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0 (video-aff audio fetch)'])
                    ->get($url);
            } catch (ConnectionException $e) {
                throw new RemoteAudioException('Không tải được link nhạc.', 0, $e);
            }

            if ($response->redirect()) {
                $location = (string) $response->header('Location');
                $url = str_starts_with($location, 'http') ? $location : rtrim(self::origin($url), '/').'/'.ltrim($location, '/');

                continue;
            }
            if (! $response->successful()) {
                throw new RemoteAudioException("Link nhạc trả lỗi HTTP {$response->status()}.");
            }

            // Đọc từng phần, dừng khi vượt giới hạn (không tải cả file lớn vào bộ nhớ).
            $tooLarge = new RemoteAudioException('File nhạc quá lớn (tối đa '.round($maxBytes / 1024 / 1024).'MB).');
            if ((int) $response->header('Content-Length') > $maxBytes) {
                throw $tooLarge;
            }
            $body = $response->toPsrResponse()->getBody();
            $contents = '';
            while (! $body->eof()) {
                $contents .= $body->read(65536);
                if (strlen($contents) > $maxBytes) {
                    throw $tooLarge;
                }
            }

            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: '';

            return isset(self::EXTENSIONS[$mime]) ? ['contents' => $contents, 'mime' => $mime, 'extension' => self::EXTENSIONS[$mime]] : null;
        }

        throw new RemoteAudioException('Link nhạc chuyển hướng quá nhiều lần.');
    }

    /** @return array{string, int, string} host, cổng, IP công khai đã kiểm tra */
    private static function checkUrl(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new RemoteAudioException('Link nhạc phải bắt đầu bằng http:// hoặc https://.');
        }

        $ips = self::$resolver ? (self::$resolver)($host) : (filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []));
        $public = fn (string $ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        if ($ips === [] || ! collect($ips)->every($public)) {
            throw new RemoteAudioException('Không tải nhạc từ địa chỉ này được.');
        }

        return [$host, (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)), $ips[0]];
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
