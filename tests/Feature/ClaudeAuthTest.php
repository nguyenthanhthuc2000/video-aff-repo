<?php

namespace Tests\Feature;

use App\Http\Controllers\ScriptController;
use App\Models\AppSetting;
use App\Models\User;
use App\Services\Script\ClaudeCliScriptWriter;
use App\Services\Script\ScriptWriter;
use App\Support\ScriptDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClaudeAuthTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'http://browser:3000';

    protected function setUp(): void
    {
        parent::setUp();
        config(['videoaff.muse_browser.url' => self::URL, 'videoaff.script_driver' => 'web', 'videoaff.claude_cli.oauth_token' => null]);
    }

    public function test_ui_token_switches_web_mode_to_claude_cli_and_is_encrypted()
    {
        $this->assertTrue(ScriptController::webMode());

        AppSetting::put(AppSetting::CLAUDE_OAUTH_TOKEN, 'sk-ant-oat01-secret');

        $this->assertSame('claude_cli', ScriptDriver::current());
        $this->assertFalse(ScriptController::webMode());
        $this->assertInstanceOf(ClaudeCliScriptWriter::class, app(ScriptWriter::class));
        $this->assertStringNotContainsString('secret', DB::table('app_settings')->value('value'));
    }

    public function test_login_flow_stores_token()
    {
        Http::fake([
            self::URL.'/claude/login/start' => Http::response(['url' => 'https://claude.com/cai/oauth/authorize?x=1']),
            self::URL.'/claude/login/code' => Http::response(['state' => 'exchanging']),
            self::URL.'/claude/login/status' => Http::sequence()
                ->push(['state' => 'exchanging'])
                ->push(['state' => 'done', 'token' => 'sk-ant-oat01-abcdefghijklmnopqrstuvwxyz']),
        ]);
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->post(route('claude.start'));
        $this->actingAs($user)->get(route('claude.auth'))
            ->assertInertia(fn (Assert $page) => $page->component('claude/auth')->where('loginUrl', 'https://claude.com/cai/oauth/authorize?x=1')->where('source', null));

        $this->actingAs($user)->post(route('claude.code'), ['code' => 'abc#def'])->assertSessionHasNoErrors();

        // Lần hỏi đầu: vẫn đang đổi mã.
        $this->actingAs($user)->get(route('claude.auth'))
            ->assertInertia(fn (Assert $page) => $page->where('pending', true)->where('source', null));

        $this->actingAs($user)->get(route('claude.auth'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('flash.status', fn ($status) => str_starts_with($status, 'Đã đăng nhập Claude'))
                ->where('driver', 'claude_cli')
                ->where('source', 'ui')
                ->where('tokenHint', 'sk-ant-oat01-a…wxyz')
                ->where('loginUrl', null)
                ->where('pending', false));

        $this->assertSame('sk-ant-oat01-abcdefghijklmnopqrstuvwxyz', AppSetting::get(AppSetting::CLAUDE_OAUTH_TOKEN));
        Http::assertSent(fn (Request $request) => $request->url() === self::URL.'/claude/login/code' && $request['code'] === 'abc#def');
    }

    public function test_bad_code_shows_friendly_error()
    {
        Http::fake([
            self::URL.'/claude/login/code' => Http::response(['state' => 'exchanging']),
            self::URL.'/claude/login/status' => Http::response(['state' => 'error', 'message' => 'Claude báo lỗi: OAuth error: Request failed with status code 400']),
        ]);
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->post(route('claude.code'), ['code' => 'x']);
        $this->actingAs($user)->get(route('claude.auth'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('pending', false)
                ->where('loginError', 'Mã không đúng hoặc đã hết hạn. Bấm "Bắt đầu đăng nhập" để lấy link mới.'));
        $this->assertNull(AppSetting::get(AppSetting::CLAUDE_OAUTH_TOKEN));
    }

    public function test_expired_session_is_reported_on_code()
    {
        Http::fake([self::URL.'/claude/login/code' => Http::response(['message' => 'Phiên đăng nhập Claude đã hết.'], 409)]);

        $this->actingAs(User::factory()->admin()->create())->post(route('claude.code'), ['code' => 'x'])
            ->assertSessionHasErrors(['code' => 'Phiên đăng nhập Claude đã hết.']);
    }

    public function test_paste_token_validates_and_delete_removes()
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->post(route('claude.token'), ['token' => 'sk-ant-api03-nope'])->assertSessionHasErrors('token');
        $this->actingAs($user)->post(route('claude.token'), ['token' => ' sk-ant-oat01-ok '])->assertSessionHasNoErrors();
        $this->assertSame('sk-ant-oat01-ok', AppSetting::get(AppSetting::CLAUDE_OAUTH_TOKEN));

        $this->actingAs($user)->delete(route('claude.destroy'));
        $this->assertNull(AppSetting::get(AppSetting::CLAUDE_OAUTH_TOKEN));
        $this->assertSame('web', ScriptDriver::current());
    }

    public function test_requires_login()
    {
        $this->get(route('claude.auth'))->assertRedirect(route('login'));
    }
}
