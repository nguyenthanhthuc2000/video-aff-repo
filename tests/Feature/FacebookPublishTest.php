<?php

namespace Tests\Feature;

use App\Enums\RenderStatus;
use App\Enums\VideoProjectStatus;
use App\Jobs\PublishFacebookReelJob;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\VideoProject;
use App\Services\Social\FacebookClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Kết nối Trang Facebook và đăng video đã xuất dạng Reels. */
class FacebookPublishTest extends TestCase
{
    use RefreshDatabase;

    private const GRAPH = 'https://graph.facebook.com/v23.0';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['videoaff.facebook' => ['app_id' => '123', 'app_secret' => 'app-secret', 'graph_version' => 'v23.0']]);
    }

    public function test_connect_saves_pages_with_encrypted_tokens()
    {
        $user = User::factory()->create();
        Http::fake([
            self::GRAPH.'/oauth/access_token*' => Http::sequence()->push(['access_token' => 'short'])->push(['access_token' => 'long']),
            self::GRAPH.'/me/accounts*' => Http::response(['data' => [['id' => '987', 'name' => 'Shop Váy', 'access_token' => 'page-token']]]),
        ]);

        $response = $this->actingAs($user)->post(route('social.facebook.connect'));
        $response->assertRedirect();
        $this->assertStringContainsString('facebook.com/v23.0/dialog/oauth', $response->headers->get('Location'));
        $this->assertStringContainsString('pages_manage_posts', $response->headers->get('Location'));
        $state = session('facebook_state');

        // Sai state: không lưu.
        $this->actingAs($user)->withSession(['facebook_state' => $state])->get(route('social.facebook.callback', ['state' => 'x', 'code' => 'c']));
        $this->assertSame(0, SocialAccount::count());

        $this->actingAs($user)->withSession(['facebook_state' => $state])
            ->get(route('social.facebook.callback', ['state' => $state, 'code' => 'c']))
            ->assertRedirect(route('social.index'));

        $account = SocialAccount::sole();
        $this->assertSame(['987', 'Shop Váy', 'page-token'], [$account->external_id, $account->name, $account->access_token]);
        $this->assertStringNotContainsString('page-token', (string) $account->getRawOriginal('access_token'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'fb_exchange_token=short'));

        $this->actingAs($user)->get(route('social.index'))->assertInertia(fn ($page) => $page
            ->component('settings/social')
            ->where('accounts.0.name', 'Shop Váy')
            ->missing('accounts.0.access_token'));
    }

    public function test_publish_uploads_reel_and_waits_until_published()
    {
        $project = VideoProject::factory()->create(['status' => VideoProjectStatus::Done]);
        $render = $project->renders()->create(['hook' => 0, 'status' => RenderStatus::Done, 'path' => 'renders/1.mp4', 'duration_ms' => 25000]);
        Storage::disk('public')->put('renders/1.mp4', 'video-bytes');
        $account = SocialAccount::create(['user_id' => $project->user_id, 'provider' => 'facebook', 'external_id' => '987', 'name' => 'Shop Váy', 'access_token' => 'page-token']);
        $other = SocialAccount::create(['user_id' => User::factory()->create()->id, 'provider' => 'facebook', 'external_id' => '555', 'name' => 'Người khác', 'access_token' => 't']);

        Queue::fake();
        // Không đăng được lên Trang của người khác.
        $this->actingAs($project->user)->post(route('projects.social-posts.store', $project), ['account_ids' => [$other->id], 'caption' => 'Hi'])
            ->assertSessionHasErrors('account_ids.0');
        $this->actingAs($project->user)->post(route('projects.social-posts.store', $project), ['account_ids' => [$account->id], 'caption' => "Váy xinh\n#ootd"]);
        Queue::assertPushed(PublishFacebookReelJob::class);
        $post = SocialPost::sole();
        $this->assertSame([$render->id, 'pending', 'Shop Váy'], [$post->render_id, $post->status, $post->account_name]);

        Http::fake([
            self::GRAPH.'/987/video_reels' => Http::sequence()->push(['video_id' => 'v1', 'upload_url' => 'u'])->push(['success' => true]),
            'https://rupload.facebook.com/*' => Http::response(['success' => true]),
            self::GRAPH.'/v1*' => Http::sequence()
                ->push(['status' => ['video_status' => 'processing', 'publishing_phase' => ['status' => 'in_progress']]])
                ->push(['status' => ['video_status' => 'ready', 'publishing_phase' => ['status' => 'complete']], 'permalink_url' => '/reel/v1']),
        ]);

        foreach ([PublishFacebookReelJob::POLL_SECONDS, PublishFacebookReelJob::POLL_SECONDS] as $delay) {
            $job = (new PublishFacebookReelJob($post))->withFakeQueueInteractions();
            $job->handle(app(FacebookClient::class));
            $job->assertReleased($delay);
        }
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://rupload.facebook.com/video-upload/v23.0/v1')
            && $request->header('Authorization')[0] === 'OAuth page-token' && $request->body() === 'video-bytes');
        Http::assertSent(fn (Request $request) => $request->url() === self::GRAPH.'/987/video_reels'
            && ($request['upload_phase'] ?? null) === 'finish' && $request['description'] === "Váy xinh\n#ootd" && $request['video_state'] === 'PUBLISHED');

        (new PublishFacebookReelJob($post))->withFakeQueueInteractions()->handle(app(FacebookClient::class));
        $post->refresh();
        $this->assertSame('published', $post->status);
        $this->assertSame('https://www.facebook.com/reel/v1', $post->permalink);
    }

    public function test_expired_token_marks_post_failed_with_hint()
    {
        $project = VideoProject::factory()->create();
        $render = $project->renders()->create(['hook' => 0, 'status' => RenderStatus::Done, 'path' => 'renders/1.mp4', 'duration_ms' => 25000]);
        Storage::disk('public')->put('renders/1.mp4', 'video-bytes');
        $account = SocialAccount::create(['user_id' => $project->user_id, 'provider' => 'facebook', 'external_id' => '987', 'name' => 'Shop', 'access_token' => 'old']);
        $post = $project->socialPosts()->create(['render_id' => $render->id, 'social_account_id' => $account->id, 'provider' => 'facebook', 'account_name' => 'Shop', 'caption' => 'C']);
        Http::fake([self::GRAPH.'/*' => Http::response(['error' => ['message' => 'Session has expired', 'code' => 190]], 400)]);

        $job = (new PublishFacebookReelJob($post))->withFakeQueueInteractions();
        $job->handle(app(FacebookClient::class));
        $job->assertFailed();
        $job->failed(new \RuntimeException('Facebook báo lỗi: Session has expired Hãy kết nối lại Facebook.'));
        $this->assertSame('failed', $post->fresh()->status);
        $this->assertStringContainsString('kết nối lại', $post->fresh()->error);
    }

    public function test_public_legal_pages_for_meta_review()
    {
        foreach (['legal.privacy' => 'privacy', 'legal.terms' => 'terms', 'legal.data-deletion' => 'data-deletion'] as $route => $page) {
            $this->get(route($route))->assertOk()->assertInertia(fn ($inertia) => $inertia->component('legal')->where('page', $page));
        }
    }
}
