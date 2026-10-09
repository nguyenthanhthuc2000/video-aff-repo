<?php

namespace App\Http\Controllers;

use App\Services\Image\MuseBrowserClient;
use App\Services\Image\MuseBrowserException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Màn hình xác thực muse.ai cho trình duyệt ngầm: nhập email/số điện thoại, mã OTP gửi về mail, mật khẩu nếu được hỏi. */
class MuseAuthController extends Controller
{
    public function __construct(private readonly MuseBrowserClient $client) {}

    public function show(): Response
    {
        return Inertia::render('muse/auth', [
            'enabled' => config('videoaff.image_driver') === 'browser',
            'status' => fn () => $this->call(fn () => $this->client->status()),
        ]);
    }

    public function refresh(): RedirectResponse
    {
        $this->call(fn () => $this->client->status(true));

        return back();
    }

    public function login(Request $request): RedirectResponse
    {
        $validated = $request->validate(['email' => ['required', 'string', 'max:255']], [], ['email' => 'email hoặc số điện thoại']);

        return $this->step('email', fn () => $this->client->login($validated['email']));
    }

    public function code(Request $request): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:20']], [], ['code' => 'mã OTP']);

        return $this->step('code', fn () => $this->client->submitCode($validated['code']));
    }

    public function password(Request $request): RedirectResponse
    {
        // Mật khẩu chỉ chuyển thẳng cho trình duyệt ngầm, không lưu.
        $validated = $request->validate(['password' => ['required', 'string', 'max:255']], [], ['password' => 'mật khẩu']);

        return $this->step('password', fn () => $this->client->submitPassword($validated['password']));
    }

    public function logout(): RedirectResponse
    {
        $this->call(fn () => $this->client->logout());

        return back()->with('status', 'Đã đăng xuất muse.ai.');
    }

    public function screenshot(): HttpResponse
    {
        try {
            $png = $this->client->screenshot();
        } catch (\Throwable) {
            abort(503, 'Dịch vụ trình duyệt chưa chạy.');
        }

        return response($png, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'no-store']);
    }

    private function step(string $field, callable $action): RedirectResponse
    {
        $status = $this->call($action);
        if ($status['state'] === 'error') {
            throw ValidationException::withMessages([$field => $status['message']]);
        }

        return back()->with('status', $status['message']);
    }

    /** Lỗi kết nối/dịch vụ trả về dạng trạng thái để màn hình vẫn hiển thị được. */
    private function call(callable $action): array
    {
        try {
            return $action();
        } catch (ConnectionException) {
            return ['state' => 'error', 'message' => 'Không kết nối được dịch vụ trình duyệt. Chạy: docker compose up -d browser', 'email' => null];
        } catch (MuseBrowserException $e) {
            return ['state' => 'error', 'message' => $e->getMessage(), 'email' => null];
        }
    }
}
