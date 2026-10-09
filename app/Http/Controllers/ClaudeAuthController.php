<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Services\Claude\ClaudeCli;
use App\Services\Claude\ClaudeCliException;
use App\Services\Image\MuseBrowserClient;
use App\Services\Image\MuseBrowserException;
use App\Support\ScriptDriver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Màn hình Xác thực Claude: đăng nhập gói Claude Pro/Max bằng `claude setup-token` (chạy trong dịch vụ browser),
 * hoặc dán token đã tạo trên máy. Token lưu mã hoá trong database.
 */
class ClaudeAuthController extends Controller
{
    public function __construct(private readonly MuseBrowserClient $helper) {}

    public function show(Request $request): Response
    {
        $loginError = $this->collectLoginResult($request);
        $uiToken = AppSetting::get(AppSetting::CLAUDE_OAUTH_TOKEN);
        $token = ScriptDriver::claudeOauthToken();

        return Inertia::render('claude/auth', [
            'driver' => ScriptDriver::current(),
            'source' => $uiToken ? 'ui' : ($token ? 'env' : null),
            'tokenHint' => $token ? substr($token, 0, 14).'…'.substr($token, -4) : null,
            'loginUrl' => $request->session()->get('claude_login_url'),
            'pending' => (bool) $request->session()->get('claude_login_pending'),
            'loginError' => $loginError,
        ]);
    }

    /** Khi đang chờ đổi mã: lấy kết quả từ dịch vụ browser, lưu token nếu xong. Trả lỗi nếu có. */
    private function collectLoginResult(Request $request): ?string
    {
        if (! $request->session()->get('claude_login_pending')) {
            return null;
        }

        try {
            $result = $this->helper->claudeLoginStatus();
        } catch (ConnectionException|MuseBrowserException) {
            return null;
        }

        if ($result['state'] === 'exchanging' || $result['state'] === 'awaiting_code') {
            return null;
        }

        $request->session()->forget(['claude_login_pending', 'claude_login_url']);

        if ($result['state'] === 'done' && str_starts_with($result['token'] ?? '', 'sk-ant-oat')) {
            AppSetting::put(AppSetting::CLAUDE_OAUTH_TOKEN, $result['token']);
            $request->session()->now('status', 'Đã đăng nhập Claude. Viết kịch bản và phân tích sản phẩm sẽ chạy ngầm bằng gói của bạn.');

            return null;
        }

        $message = $result['message'] ?? 'Phiên đăng nhập đã hết, bấm "Bắt đầu đăng nhập" lại.';

        return str_contains($message, 'status code 400')
            ? 'Mã không đúng hoặc đã hết hạn. Bấm "Bắt đầu đăng nhập" để lấy link mới.'
            : $message;
    }

    public function start(Request $request): RedirectResponse
    {
        try {
            $url = $this->helper->startClaudeLogin();
        } catch (ConnectionException) {
            return back()->with('status', 'Không kết nối được dịch vụ browser. Chạy: docker compose up -d browser');
        } catch (MuseBrowserException $e) {
            return back()->with('status', $e->getMessage());
        }

        $request->session()->put('claude_login_url', $url);

        return back();
    }

    public function code(Request $request): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:500']], [], ['code' => 'mã đăng nhập']);

        try {
            $this->helper->submitClaudeCode($validated['code']);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['code' => 'Không kết nối được dịch vụ browser.']);
        } catch (MuseBrowserException $e) {
            $request->session()->forget('claude_login_url');

            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }

        $request->session()->put('claude_login_pending', true);

        return back();
    }

    /** Dán token tạo bằng `claude setup-token` trên máy khác. */
    public function token(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'starts_with:sk-ant-oat', 'max:500'],
        ], ['token.starts_with' => 'Token gói Claude bắt đầu bằng sk-ant-oat.'], ['token' => 'token']);

        AppSetting::put(AppSetting::CLAUDE_OAUTH_TOKEN, trim($validated['token']));

        return back()->with('status', 'Đã lưu token Claude.');
    }

    /** Gọi thử một lệnh rất ngắn (tính vào hạn mức gói). */
    public function test(ClaudeCli $cli): RedirectResponse
    {
        try {
            $result = $cli->json(
                'Trả lời ngắn gọn.',
                [['type' => 'text', 'text' => 'Trả về {"ok": true}.']],
                ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']], 'required' => ['ok'], 'additionalProperties' => false],
                'auth_test',
            );
        } catch (ClaudeCliException $e) {
            return back()->with('status', 'Kiểm tra thất bại: '.$e->getMessage());
        }

        return back()->with('status', ($result['ok'] ?? false) ? 'Claude hoạt động bình thường.' : 'Claude trả kết quả lạ, thử lại.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        AppSetting::put(AppSetting::CLAUDE_OAUTH_TOKEN, null);
        $request->session()->forget('claude_login_url');

        return back()->with('status', 'Đã xoá token Claude lưu trên giao diện.');
    }
}
