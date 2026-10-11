<?php

namespace App\Services\Social;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Graph API của Facebook: đăng nhập lấy quyền quản lý Trang, rồi đăng Reels lên Trang
 * (bắt đầu phiên tải → tải file lên rupload → kết thúc và đăng → hỏi trạng thái xử lý).
 */
class FacebookClient
{
    /** Quyền cần: liệt kê Trang, đọc và đăng bài lên Trang. */
    public const SCOPES = ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts'];

    public function __construct(
        private readonly ?string $appId,
        private readonly ?string $appSecret,
        private readonly string $version,
    ) {}

    public static function fromConfig(): self
    {
        return new self(config('videoaff.facebook.app_id'), config('videoaff.facebook.app_secret'), config('videoaff.facebook.graph_version'));
    }

    public function configured(): bool
    {
        return filled($this->appId) && filled($this->appSecret);
    }

    public function loginUrl(string $redirectUri, string $state): string
    {
        return "https://www.facebook.com/{$this->version}/dialog/oauth?".http_build_query([
            'client_id' => $this->appId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => implode(',', self::SCOPES),
            'response_type' => 'code',
        ]);
    }

    /**
     * Đổi mã đăng nhập lấy mã dài hạn của người dùng, rồi lấy các Trang người đó quản lý
     * (mã của Trang lấy từ mã dài hạn thì không hết hạn).
     *
     * @return list<array{id: string, name: string, access_token: string}>
     *
     * @throws RuntimeException
     */
    public function pagesFromCode(string $code, string $redirectUri): array
    {
        $short = $this->get('oauth/access_token', [
            'client_id' => $this->appId, 'client_secret' => $this->appSecret, 'redirect_uri' => $redirectUri, 'code' => $code,
        ])['access_token'] ?? throw new RuntimeException('Facebook không trả mã truy cập.');

        $long = $this->get('oauth/access_token', [
            'grant_type' => 'fb_exchange_token', 'client_id' => $this->appId, 'client_secret' => $this->appSecret, 'fb_exchange_token' => $short,
        ])['access_token'] ?? $short;

        $pages = $this->get('me/accounts', ['fields' => 'id,name,access_token', 'limit' => 100, 'access_token' => $long])['data'] ?? [];

        return array_values(array_filter(array_map(
            fn ($page) => isset($page['id'], $page['name'], $page['access_token']) ? ['id' => (string) $page['id'], 'name' => $page['name'], 'access_token' => $page['access_token']] : null,
            $pages,
        )));
    }

    /** Mở phiên tải Reels, trả mã video. */
    public function startReel(string $pageId, string $token): string
    {
        return (string) ($this->post("{$pageId}/video_reels", ['upload_phase' => 'start', 'access_token' => $token])['video_id']
            ?? throw new RuntimeException('Facebook không mở được phiên tải video.'));
    }

    /** Tải nguyên file video lên (một lần). */
    public function uploadReel(string $videoId, string $token, string $contents): void
    {
        try {
            $response = Http::timeout(600)
                ->withHeaders(['Authorization' => "OAuth {$token}", 'offset' => '0', 'file_size' => (string) strlen($contents)])
                ->withBody($contents, 'application/octet-stream')
                ->post("https://rupload.facebook.com/video-upload/{$this->version}/{$videoId}");
        } catch (ConnectionException $e) {
            throw new SocialPostException('Không kết nối được Facebook khi tải video lên.', true, $e);
        }
        $this->check($response);
    }

    /** Kết thúc tải và đăng Reels lên Trang với caption. */
    public function publishReel(string $pageId, string $token, string $videoId, string $caption): void
    {
        $this->post("{$pageId}/video_reels", [
            'upload_phase' => 'finish', 'video_id' => $videoId, 'video_state' => 'PUBLISHED', 'description' => $caption, 'access_token' => $token,
        ]);
    }

    /**
     * Trạng thái xử lý / đăng của video.
     *
     * @return array{state: 'processing'|'published'|'failed', error: ?string, permalink: ?string}
     */
    public function reelStatus(string $videoId, string $token): array
    {
        $data = $this->get($videoId, ['fields' => 'status,permalink_url', 'access_token' => $token]);
        $status = $data['status'] ?? [];
        $permalink = $data['permalink_url'] ?? null;
        if (is_string($permalink) && str_starts_with($permalink, '/')) {
            $permalink = 'https://www.facebook.com'.$permalink;
        }

        foreach (['uploading_phase', 'processing_phase', 'publishing_phase'] as $phase) {
            if (($status[$phase]['status'] ?? null) === 'error') {
                return ['state' => 'failed', 'error' => (string) ($status[$phase]['error']['message'] ?? 'Facebook báo lỗi khi xử lý video.'), 'permalink' => null];
            }
        }
        if (($status['video_status'] ?? null) === 'error') {
            return ['state' => 'failed', 'error' => 'Facebook báo lỗi khi xử lý video.', 'permalink' => null];
        }
        $published = ($status['publishing_phase']['status'] ?? null) === 'complete' || ($status['video_status'] ?? null) === 'ready';

        return ['state' => $published ? 'published' : 'processing', 'error' => null, 'permalink' => $permalink];
    }

    private function get(string $path, array $query): array
    {
        try {
            return $this->check(Http::timeout(60)->get("https://graph.facebook.com/{$this->version}/{$path}", $query));
        } catch (ConnectionException $e) {
            throw new SocialPostException('Không kết nối được Facebook.', true, $e);
        }
    }

    private function post(string $path, array $data): array
    {
        try {
            return $this->check(Http::timeout(120)->asForm()->post("https://graph.facebook.com/{$this->version}/{$path}", $data));
        } catch (ConnectionException $e) {
            throw new SocialPostException('Không kết nối được Facebook.', true, $e);
        }
    }

    private function check(Response $response): array
    {
        if ($response->successful()) {
            return $response->json() ?? [];
        }
        $error = $response->json('error') ?? [];
        $message = (string) ($error['message'] ?? "HTTP {$response->status()}");
        // Mã 190: mã truy cập hết hạn / bị thu hồi; 10, 200: thiếu quyền.
        $hint = match ((int) ($error['code'] ?? 0)) {
            190 => ' Hãy kết nối lại Facebook.',
            10, 200 => ' Tài khoản chưa cấp đủ quyền đăng bài lên Trang.',
            default => '',
        };

        throw new SocialPostException('Facebook báo lỗi: '.$message.$hint, $response->status() >= 500 || $response->status() === 429);
    }
}
