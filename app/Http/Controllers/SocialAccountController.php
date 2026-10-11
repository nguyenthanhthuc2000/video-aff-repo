<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Services\Social\FacebookClient;
use App\Services\Social\TikTokClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/** Kết nối tài khoản mạng xã hội để đăng video: Trang Facebook, tài khoản TikTok. */
class SocialAccountController extends Controller
{
    public function index(Request $request, FacebookClient $facebook, TikTokClient $tiktok): Response
    {
        return Inertia::render('settings/social', [
            'accounts' => $request->user()->socialAccounts()->orderBy('provider')->orderBy('name')->get()->map->present(),
            'facebookConfigured' => $facebook->configured(),
            'facebookRedirectUri' => route('social.facebook.callback'),
            'tiktokConfigured' => $tiktok->configured(),
            'tiktokRedirectUri' => route('social.tiktok.callback'),
        ]);
    }

    public function tiktokConnect(Request $request, TikTokClient $tiktok): HttpResponse
    {
        if (! $tiktok->configured()) {
            return back()->with('status', 'Chưa cài TIKTOK_CLIENT_KEY / TIKTOK_CLIENT_SECRET.');
        }
        $state = Str::random(40);
        $request->session()->put('tiktok_state', $state);

        return Inertia::location($tiktok->loginUrl(route('social.tiktok.callback'), $state));
    }

    public function tiktokCallback(Request $request, TikTokClient $tiktok): RedirectResponse
    {
        $state = $request->session()->pull('tiktok_state');
        if (! $state || ! hash_equals($state, (string) $request->query('state'))) {
            return to_route('social.index')->with('status', 'Phiên kết nối TikTok không hợp lệ, hãy thử lại.');
        }
        if ($request->filled('error') || ! $request->filled('code')) {
            return to_route('social.index')->with('status', 'Bạn đã huỷ kết nối TikTok.');
        }

        try {
            $user = $tiktok->connect((string) $request->query('code'), route('social.tiktok.callback'));
        } catch (Throwable $e) {
            return to_route('social.index')->with('status', 'Kết nối TikTok thất bại: '.$e->getMessage());
        }

        SocialAccount::updateOrCreate(
            ['user_id' => $request->user()->id, 'provider' => 'tiktok', 'external_id' => $user['open_id']],
            collect($user)->except('open_id')->all(),
        );

        return to_route('social.index')->with('status', "Đã kết nối TikTok {$user['name']}.");
    }

    public function facebookConnect(Request $request, FacebookClient $facebook): HttpResponse
    {
        if (! $facebook->configured()) {
            return back()->with('status', 'Chưa cài FACEBOOK_APP_ID / FACEBOOK_APP_SECRET.');
        }
        $state = Str::random(40);
        $request->session()->put('facebook_state', $state);

        return Inertia::location($facebook->loginUrl(route('social.facebook.callback'), $state));
    }

    public function facebookCallback(Request $request, FacebookClient $facebook): RedirectResponse
    {
        $state = $request->session()->pull('facebook_state');
        if (! $state || ! hash_equals($state, (string) $request->query('state'))) {
            return to_route('social.index')->with('status', 'Phiên kết nối Facebook không hợp lệ, hãy thử lại.');
        }
        if ($request->filled('error') || ! $request->filled('code')) {
            return to_route('social.index')->with('status', 'Bạn đã huỷ kết nối Facebook.');
        }

        try {
            $pages = $facebook->pagesFromCode((string) $request->query('code'), route('social.facebook.callback'));
        } catch (Throwable $e) {
            return to_route('social.index')->with('status', 'Kết nối Facebook thất bại: '.$e->getMessage());
        }
        if ($pages === []) {
            return to_route('social.index')->with('status', 'Không thấy Trang nào. Khi đăng nhập hãy chọn Trang cần đăng video.');
        }

        foreach ($pages as $page) {
            SocialAccount::updateOrCreate(
                ['user_id' => $request->user()->id, 'provider' => 'facebook', 'external_id' => $page['id']],
                ['name' => $page['name'], 'access_token' => $page['access_token']],
            );
        }

        return to_route('social.index')->with('status', 'Đã kết nối '.count($pages).' Trang Facebook.');
    }

    public function destroy(Request $request, SocialAccount $account): RedirectResponse
    {
        abort_unless($account->user_id === $request->user()->id, 403);
        $account->delete();

        return back()->with('status', "Đã ngắt kết nối {$account->name}.");
    }
}
