<?php

namespace Tests\Feature;

use App\Enums\RenderStatus;
use App\Jobs\PublishFacebookReelJob;
use App\Jobs\PublishTikTokJob;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\VideoProject;
use App\Services\Social\TikTokClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Kết nối TikTok và đăng video: đăng thẳng (theo quyền xem người dùng chọn) hoặc gửi vào hộp nháp. */
class TikTokPublishTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://open.tiktokapis.com/v2';

    private const OK = ['error' => ['code' => 'ok', 'message' => '']];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['videoaff.tiktok' => ['client_key' => 'ck', 'client_secret' => 'cs']]);
    }

    private function account(VideoProject $project, array $attributes = []): SocialAccount
    {
        return SocialAccount::create([
            'user_id' => $project->user_id, 'provider' => 'tiktok', 'external_id' => 'open-1', 'name' => 'shopvay',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_expires_at' => now()->addHours(20), 'refresh_expires_at' => now()->addDays(300),
            ...$attributes,
        ]);
    }

    private function project(): VideoProject
    {
        $project = VideoProject::factory()->create();
        $project->renders()->create(['hook' => 0, 'status' => RenderStatus::Done, 'path' => 'renders/1.mp4', 'duration_ms' => 25000]);
        Storage::disk('public')->put('renders/1.mp4', 'video-bytes');

        return $project;
    }

    public function test_connect_saves_account_with_encrypted_tokens()
    {
        $user = User::factory()->create();
        Http::fake([
            self::API.'/oauth/token/' => Http::response(['access_token' => 'act', 'refresh_token' => 'rft', 'open_id' => 'open-1', 'expires_in' => 86400, 'refresh_expires_in' => 31536000]),
            self::API.'/user/info/*' => Http::response(['data' => ['user' => ['open_id' => 'open-1', 'display_name' => 'Shop Váy', 'avatar_url' => 'https://a/x.jpg']], ...self::OK]),
        ]);

        $location = $this->actingAs($user)->post(route('social.tiktok.connect'))->headers->get('Location');
        $this->assertStringContainsString('tiktok.com/v2/auth/authorize', $location);
        $this->assertStringContainsString('video.publish', urldecode($location));
        $state = session('tiktok_state');
        $verifier = session('tiktok_verifier');
        // PKCE: code_challenge là SHA256 hex của code_verifier, gửi lại code_verifier khi đổi mã.
        $this->assertStringContainsString('code_challenge='.hash('sha256', $verifier).'&code_challenge_method=S256', $location);

        $this->actingAs($user)->withSession(['tiktok_state' => $state, 'tiktok_verifier' => $verifier])
            ->get(route('social.tiktok.callback', ['state' => $state, 'code' => 'c']))->assertRedirect(route('social.index'));

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/oauth/token/') && $request['code_verifier'] === $verifier);
        $account = SocialAccount::sole();
        $this->assertSame(['tiktok', 'open-1', 'Shop Váy', 'act', 'rft'], [$account->provider, $account->external_id, $account->name, $account->access_token, $account->refresh_token]);
        $this->assertTrue($account->token_expires_at->isAfter(now()->addHours(23)));
        $this->assertStringNotContainsString('rft', (string) $account->getRawOriginal('refresh_token'));
    }

    public function test_direct_post_uses_chosen_privacy_and_ai_label_then_publishes()
    {
        $project = $this->project();
        $account = $this->account($project);

        Queue::fake();
        // Đăng thẳng phải tự chọn quyền xem; nội dung có thương hiệu không được để "Chỉ mình tôi".
        $this->actingAs($project->user)->post(route('projects.social-posts.store', $project), ['account_ids' => [$account->id], 'caption' => 'C', 'tiktok' => ['mode' => 'direct']])
            ->assertSessionHasErrors('tiktok.privacy_level');
        $this->actingAs($project->user)->post(route('projects.social-posts.store', $project), [
            'account_ids' => [$account->id], 'caption' => 'C', 'tiktok' => ['mode' => 'direct', 'privacy_level' => 'SELF_ONLY', 'brand_content' => true],
        ])->assertSessionHasErrors('tiktok.privacy_level');

        $this->actingAs($project->user)->post(route('projects.social-posts.store', $project), [
            'account_ids' => [$account->id], 'caption' => "Váy xinh\n#ootd",
            'tiktok' => ['mode' => 'direct', 'privacy_level' => 'PUBLIC_TO_EVERYONE', 'allow_comment' => true, 'brand_content' => true],
        ]);
        Queue::assertPushed(PublishTikTokJob::class);
        Queue::assertNotPushed(PublishFacebookReelJob::class);
        $post = SocialPost::sole();

        Http::fake([
            self::API.'/post/publish/creator_info/query/' => Http::response(['data' => [
                'creator_nickname' => 'shopvay', 'privacy_level_options' => ['PUBLIC_TO_EVERYONE', 'SELF_ONLY'], 'max_video_post_duration_sec' => 600,
                'comment_disabled' => false, 'duet_disabled' => false, 'stitch_disabled' => true,
            ], ...self::OK]),
            self::API.'/post/publish/video/init/' => Http::response(['data' => ['publish_id' => 'p1', 'upload_url' => 'https://upload.tiktok/u1'], ...self::OK]),
            'https://upload.tiktok/*' => Http::response('', 201),
            self::API.'/post/publish/status/fetch/' => Http::sequence()
                ->push(['data' => ['status' => 'PROCESSING_UPLOAD'], ...self::OK])
                ->push(['data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => [7123]], ...self::OK]),
        ]);

        foreach ([1, 2] as $_) {
            $job = (new PublishTikTokJob($post))->withFakeQueueInteractions();
            $job->handle(app(TikTokClient::class));
            $job->assertReleased(PublishTikTokJob::POLL_SECONDS);
        }
        Http::assertSent(fn (Request $request) => $request->url() === self::API.'/post/publish/video/init/'
            && $request['post_info']['privacy_level'] === 'PUBLIC_TO_EVERYONE'
            && $request['post_info']['is_aigc'] === true
            && $request['post_info']['brand_content_toggle'] === true
            && $request['post_info']['disable_comment'] === false
            && $request['post_info']['disable_stitch'] === true
            && $request['post_info']['title'] === "Váy xinh\n#ootd"
            && $request['source_info']['video_size'] === 11 && $request['source_info']['total_chunk_count'] === 1);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://upload.tiktok/u1' && $request->header('Content-Range')[0] === 'bytes 0-10/11');

        (new PublishTikTokJob($post))->withFakeQueueInteractions()->handle(app(TikTokClient::class));
        $post->refresh();
        $this->assertSame('published', $post->status);
        $this->assertSame('https://www.tiktok.com/@shopvay/video/7123', $post->permalink);
    }

    public function test_inbox_upload_and_expired_token_is_refreshed()
    {
        $project = $this->project();
        $account = $this->account($project, ['token_expires_at' => now()->subMinute()]);
        $post = $project->socialPosts()->create([
            'render_id' => $project->renders()->first()->id, 'social_account_id' => $account->id, 'provider' => 'tiktok', 'account_name' => 'shopvay',
            'caption' => 'C', 'options' => ['mode' => 'inbox'],
        ]);
        Http::fake([
            self::API.'/oauth/token/' => Http::response(['access_token' => 'new-tok', 'refresh_token' => 'new-ref', 'expires_in' => 86400, 'refresh_expires_in' => 31536000]),
            self::API.'/post/publish/inbox/video/init/' => Http::response(['data' => ['publish_id' => 'p2', 'upload_url' => 'https://upload.tiktok/u2'], ...self::OK]),
            'https://upload.tiktok/*' => Http::response('', 201),
            self::API.'/post/publish/status/fetch/' => Http::response(['data' => ['status' => 'SEND_TO_USER_INBOX'], ...self::OK]),
        ]);

        (new PublishTikTokJob($post))->withFakeQueueInteractions()->handle(app(TikTokClient::class));
        (new PublishTikTokJob($post))->withFakeQueueInteractions()->handle(app(TikTokClient::class));

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/oauth/token/') && $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === 'ref');
        Http::assertSent(fn (Request $request) => $request->url() === self::API.'/post/publish/inbox/video/init/' && ! isset($request['post_info']) && $request->header('Authorization')[0] === 'Bearer new-tok');
        $this->assertSame('new-ref', $account->fresh()->refresh_token);
        $this->assertSame('inbox', $post->fresh()->status);
    }

    public function test_unaudited_app_error_explains_private_only()
    {
        $project = $this->project();
        $account = $this->account($project);
        $post = $project->socialPosts()->create([
            'render_id' => $project->renders()->first()->id, 'social_account_id' => $account->id, 'provider' => 'tiktok', 'account_name' => 'shopvay',
            'caption' => 'C', 'options' => ['mode' => 'direct', 'privacy_level' => 'PUBLIC_TO_EVERYONE'],
        ]);
        Http::fake([
            self::API.'/post/publish/creator_info/query/' => Http::response(['data' => ['privacy_level_options' => ['PUBLIC_TO_EVERYONE']], ...self::OK]),
            self::API.'/post/publish/video/init/' => Http::response(['error' => ['code' => 'unaudited_client_can_only_post_to_private_accounts', 'message' => 'Unaudited']], 403),
        ]);

        $job = (new PublishTikTokJob($post))->withFakeQueueInteractions();
        $job->handle(app(TikTokClient::class));
        $job->assertFailed();
    }
}
