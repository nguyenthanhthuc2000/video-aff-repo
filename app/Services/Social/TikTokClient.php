<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * TikTok Login Kit + Content Posting API: đăng nhập lấy quyền, rồi đăng video bằng cách tải file lên
 * (đăng thẳng lên tài khoản, hoặc gửi vào hộp nháp để người dùng tự bấm đăng trong app TikTok).
 * App chưa được TikTok kiểm duyệt (audit) thì đăng thẳng chỉ ở chế độ riêng tư (SELF_ONLY).
 */
class TikTokClient
{
    public const SCOPES = ['user.info.basic', 'video.publish', 'video.upload'];

    private const API = 'https://open.tiktokapis.com/v2';

    /** File dưới mức này tải một lần; lớn hơn thì chia đoạn (TikTok: mỗi đoạn 5–64 MB, đoạn cuối gộp phần dư). */
    public const SINGLE_UPLOAD_BYTES = 64 * 1024 * 1024;

    public const CHUNK_BYTES = 10 * 1024 * 1024;

    public function __construct(private readonly ?string $clientKey, private readonly ?string $clientSecret) {}

    public static function fromConfig(): self
    {
        return new self(config('videoaff.tiktok.client_key'), config('videoaff.tiktok.client_secret'));
    }

    public function configured(): bool
    {
        return filled($this->clientKey) && filled($this->clientSecret);
    }

    public function loginUrl(string $redirectUri, string $state): string
    {
        return 'https://www.tiktok.com/v2/auth/authorize/?'.http_build_query([
            'client_key' => $this->clientKey,
            'scope' => implode(',', self::SCOPES),
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }

    /**
     * Đổi mã đăng nhập lấy mã truy cập và thông tin tài khoản.
     *
     * @return array{open_id: string, name: string, avatar_url: ?string, access_token: string, refresh_token: string, token_expires_at: Carbon, refresh_expires_at: Carbon}
     */
    public function connect(string $code, string $redirectUri): array
    {
        $token = $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri]);
        $user = $this->call('get', '/user/info/?fields=open_id,display_name,avatar_url', $token['access_token'])['user'] ?? [];

        return [
            'open_id' => (string) ($user['open_id'] ?? $token['open_id']),
            'name' => (string) ($user['display_name'] ?? 'TikTok'),
            'avatar_url' => $user['avatar_url'] ?? null,
            ...$this->tokenFields($token),
        ];
    }

    /** Mã truy cập còn dùng được (tự làm mới khi sắp hết hạn và lưu lại). */
    public function accessToken(SocialAccount $account): string
    {
        if ($account->token_expires_at !== null && $account->token_expires_at->isAfter(now()->addMinutes(5))) {
            return $account->access_token;
        }
        if ($account->refresh_token === null || $account->refresh_expires_at?->isPast()) {
            throw new SocialPostException('Kết nối TikTok đã hết hạn. Hãy kết nối lại TikTok.');
        }
        $account->update($this->tokenFields($this->token(['grant_type' => 'refresh_token', 'refresh_token' => $account->refresh_token])));

        return $account->access_token;
    }

    /**
     * Thông tin người đăng (TikTok bắt buộc hỏi trước khi đăng thẳng): tên, các quyền xem cho chọn, độ dài video tối đa, đã tắt bình luận / duet / stitch chưa.
     *
     * @return array{nickname: string, privacy_level_options: list<string>, max_video_post_duration_sec: int, comment_disabled: bool, duet_disabled: bool, stitch_disabled: bool}
     */
    public function creatorInfo(string $token): array
    {
        $data = $this->call('post', '/post/publish/creator_info/query/', $token, []);

        return [
            'nickname' => (string) ($data['creator_nickname'] ?? ''),
            'privacy_level_options' => array_values($data['privacy_level_options'] ?? []),
            'max_video_post_duration_sec' => (int) ($data['max_video_post_duration_sec'] ?? 0),
            'comment_disabled' => (bool) ($data['comment_disabled'] ?? false),
            'duet_disabled' => (bool) ($data['duet_disabled'] ?? false),
            'stitch_disabled' => (bool) ($data['stitch_disabled'] ?? false),
        ];
    }

    /**
     * Mở phiên đăng: $postInfo null = gửi vào hộp nháp (người dùng tự đăng trong app), có = đăng thẳng.
     *
     * @return array{publish_id: string, upload_url: string, chunk_size: int, chunks: int}
     */
    public function initUpload(string $token, int $size, ?array $postInfo): array
    {
        [$chunkSize, $chunks] = $size <= self::SINGLE_UPLOAD_BYTES ? [$size, 1] : [self::CHUNK_BYTES, intdiv($size, self::CHUNK_BYTES)];
        $body = ['source_info' => ['source' => 'FILE_UPLOAD', 'video_size' => $size, 'chunk_size' => $chunkSize, 'total_chunk_count' => $chunks]];
        if ($postInfo !== null) {
            $body['post_info'] = $postInfo;
        }
        $data = $this->call('post', $postInfo === null ? '/post/publish/inbox/video/init/' : '/post/publish/video/init/', $token, $body);

        return [
            'publish_id' => (string) ($data['publish_id'] ?? throw new SocialPostException('TikTok không mở được phiên đăng.')),
            'upload_url' => (string) ($data['upload_url'] ?? throw new SocialPostException('TikTok không trả địa chỉ tải lên.')),
            'chunk_size' => $chunkSize,
            'chunks' => $chunks,
        ];
    }

    /** Tải file theo từng đoạn (đoạn cuối gộp phần dư). */
    public function upload(array $session, string $contents): void
    {
        $size = strlen($contents);
        for ($i = 0; $i < $session['chunks']; $i++) {
            $start = $i * $session['chunk_size'];
            $end = $i === $session['chunks'] - 1 ? $size - 1 : $start + $session['chunk_size'] - 1;
            try {
                $response = Http::timeout(600)
                    ->withHeaders(['Content-Range' => "bytes {$start}-{$end}/{$size}"])
                    ->withBody(substr($contents, $start, $end - $start + 1), 'video/mp4')
                    ->put($session['upload_url']);
            } catch (ConnectionException $e) {
                throw new SocialPostException('Không kết nối được TikTok khi tải video lên.', true, $e);
            }
            if (! $response->successful()) {
                throw new SocialPostException("TikTok từ chối file video (HTTP {$response->status()}).", $response->status() >= 500);
            }
        }
    }

    /**
     * Trạng thái đăng.
     *
     * @return array{state: 'processing'|'published'|'inbox'|'failed', error: ?string, post_id: ?string}
     */
    public function status(string $token, string $publishId): array
    {
        $data = $this->call('post', '/post/publish/status/fetch/', $token, ['publish_id' => $publishId]);
        $postIds = $data['publicaly_available_post_id'] ?? $data['publicly_available_post_id'] ?? [];

        return match ($data['status'] ?? '') {
            'PUBLISH_COMPLETE' => ['state' => 'published', 'error' => null, 'post_id' => isset($postIds[0]) ? (string) $postIds[0] : null],
            'SEND_TO_USER_INBOX' => ['state' => 'inbox', 'error' => null, 'post_id' => null],
            'FAILED' => ['state' => 'failed', 'error' => 'TikTok không đăng được video: '.($data['fail_reason'] ?? 'lỗi không rõ').'.', 'post_id' => null],
            default => ['state' => 'processing', 'error' => null, 'post_id' => null],
        };
    }

    private function token(array $params): array
    {
        try {
            $response = Http::timeout(60)->asForm()->post(self::API.'/oauth/token/', [
                'client_key' => $this->clientKey, 'client_secret' => $this->clientSecret, ...$params,
            ]);
        } catch (ConnectionException $e) {
            throw new SocialPostException('Không kết nối được TikTok.', true, $e);
        }
        $data = $response->json() ?? [];
        if (! $response->successful() || empty($data['access_token'])) {
            throw new SocialPostException('TikTok từ chối đăng nhập: '.($data['error_description'] ?? $data['error'] ?? "HTTP {$response->status()}").'. Hãy kết nối lại TikTok.');
        }

        return $data;
    }

    private function tokenFields(array $token): array
    {
        return [
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? null,
            'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 86400)),
            'refresh_expires_at' => now()->addSeconds((int) ($token['refresh_expires_in'] ?? 31536000)),
        ];
    }

    private function call(string $method, string $path, string $token, ?array $body = null): array
    {
        try {
            $request = Http::timeout(120)->withToken($token)->acceptJson();
            $response = $method === 'get' ? $request->get(self::API.$path) : $request->asJson()->post(self::API.$path, $body ?? []);
        } catch (ConnectionException $e) {
            throw new SocialPostException('Không kết nối được TikTok.', true, $e);
        }

        return $this->check($response);
    }

    private function check(Response $response): array
    {
        $error = $response->json('error') ?? [];
        $code = (string) ($error['code'] ?? ($response->successful() ? 'ok' : 'http_error'));
        if ($response->successful() && $code === 'ok') {
            return $response->json('data') ?? [];
        }
        $hint = match ($code) {
            'access_token_invalid', 'scope_not_authorized' => ' Hãy kết nối lại TikTok và cấp đủ quyền.',
            'spam_risk_too_many_posts', 'spam_risk_user_banned_from_posting' => ' Tài khoản đã đăng quá nhiều, thử lại sau.',
            'unaudited_client_can_only_post_to_private_accounts' => ' App TikTok chưa được kiểm duyệt: chỉ đăng được ở chế độ "Chỉ mình tôi", hoặc gửi vào hộp nháp.',
            default => '',
        };

        throw new SocialPostException(
            'TikTok báo lỗi: '.($error['message'] ?? $code).$hint,
            $response->status() >= 500 || $response->status() === 429 || $code === 'rate_limit_exceeded',
        );
    }
}
