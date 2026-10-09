<?php

namespace App\Services\Image;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Gọi dịch vụ trình duyệt ngầm (docker/browser/server.js) đang đăng nhập muse.ai. */
class MuseBrowserClient
{
    public function __construct(
        private readonly string $url,
        private readonly ?string $token,
        private readonly int $timeout,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            rtrim((string) config('videoaff.muse_browser.url'), '/'),
            config('videoaff.muse_browser.token'),
            config('videoaff.muse_browser.timeout'),
        );
    }

    /** @return array{state: string, message: string, email: ?string, updatedAt: string} */
    public function status(bool $refresh = false): array
    {
        return $refresh ? $this->post('/status/refresh') : $this->json($this->request(30)->get($this->url.'/status'));
    }

    public function login(string $email): array
    {
        return $this->post('/login', ['email' => $email]);
    }

    public function submitCode(string $code): array
    {
        return $this->post('/login/code', ['code' => $code]);
    }

    public function submitPassword(string $password): array
    {
        return $this->post('/login/password', ['password' => $password]);
    }

    public function logout(): array
    {
        return $this->post('/logout');
    }

    /** Bắt đầu `claude setup-token` trong dịch vụ trình duyệt, trả link đăng nhập. */
    public function startClaudeLogin(): string
    {
        return (string) $this->post('/claude/login/start')['url'];
    }

    /** Gửi mã người dùng dán từ trang đăng nhập Claude; đổi mã lấy token chạy nền. */
    public function submitClaudeCode(string $code): void
    {
        $this->post('/claude/login/code', ['code' => $code]);
    }

    /**
     * Kết quả đăng nhập Claude: exchanging (đang đổi mã), done (kèm token, chỉ trả một lần), error, idle.
     *
     * @return array{state: string, token?: string, message?: string}
     */
    public function claudeLoginStatus(): array
    {
        return $this->post('/claude/login/status');
    }

    public function screenshot(): string
    {
        $response = $this->request(60)->get($this->url.'/screenshot');
        $response->throw();

        return $response->body();
    }

    /**
     * Gửi yêu cầu tạo ảnh/video có mã riêng; dịch vụ trả về ngay, kết quả lấy bằng task().
     * adopt = chỉ theo dõi lại mã đã gửi (dịch vụ trình duyệt khởi động lại).
     *
     * @param  list<array{name: string, base64: string}>  $images
     */
    public function startTask(string $code, string $kind, string $prompt, array $images, int $timeoutMs, bool $adopt = false): array
    {
        return $this->json($this->request(120)->post($this->url.'/tasks', [
            'code' => $code,
            'kind' => $kind,
            'prompt' => $prompt,
            'images' => $images,
            'timeout_ms' => $timeoutMs,
            'adopt' => $adopt,
        ]));
    }

    /**
     * Trạng thái yêu cầu: pending, done (kèm result: mime, base64), failed (kèm error). Kết quả chỉ trả một lần.
     *
     * @return array{code: string, state: string, error: ?string, result?: array{mime: string, base64: string}}
     */
    public function task(string $code): array
    {
        return $this->json($this->request(120)->get($this->url.'/tasks/'.$code));
    }

    private function post(string $path, array $data = []): array
    {
        return $this->json($this->request(90)->post($this->url.$path, $data));
    }

    private function request(int $timeout): PendingRequest
    {
        return Http::timeout($timeout)->acceptJson()->withHeaders(array_filter(['X-Token' => $this->token]));
    }

    /** @throws MuseBrowserException */
    private function json(Response $response): array
    {
        if ($response->failed()) {
            throw new MuseBrowserException($response->json('message') ?? "Dịch vụ trình duyệt lỗi HTTP {$response->status()}.", $response->status());
        }

        return $response->json() ?? [];
    }
}
