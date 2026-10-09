<?php

namespace Tests\Feature;

use App\Enums\SceneClipStatus;
use App\Enums\SceneImageStatus;
use App\Enums\VideoProjectStatus;
use App\Jobs\GenerateSceneClipJob;
use App\Jobs\GenerateSceneImageJob;
use App\Models\Scene;
use App\Models\SceneImage;
use App\Models\User;
use App\Models\VideoProject;
use App\Services\Image\ImageGenerator;
use App\Services\Image\ImageGeneratorException;
use App\Services\Image\MuseBrowserImageGenerator;
use App\Services\Image\Prompt\ScenePromptRewriter;
use App\Services\Video\ClipGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MuseBrowserTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'http://browser:3000';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config([
            'videoaff.image_driver' => 'browser',
            'videoaff.muse_browser.url' => self::URL,
            'videoaff.muse_browser.token' => 'secret-token',
        ]);
    }

    private function museStatus(string $state, string $message = 'ok'): array
    {
        return ['state' => $state, 'message' => $message, 'email' => 'a@b.c', 'updatedAt' => now()->toIso8601String()];
    }

    public function test_auth_page_shows_browser_status()
    {
        Http::fake([self::URL.'/status' => Http::response($this->museStatus('logged_out', 'Chưa đăng nhập.'))]);

        $this->actingAs(User::factory()->create())->get(route('muse.auth'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('muse/auth')
                ->where('enabled', true)
                ->where('status.state', 'logged_out'));

        Http::assertSent(fn (Request $request) => $request->hasHeader('X-Token', 'secret-token'));
    }

    public function test_auth_page_reports_service_down()
    {
        Http::fake(fn () => throw new ConnectionException('down'));

        $this->actingAs(User::factory()->create())->get(route('muse.auth'))
            ->assertInertia(fn (Assert $page) => $page->where('status.state', 'error'));
    }

    public function test_login_then_code_flow()
    {
        Http::fake([
            self::URL.'/login' => Http::response($this->museStatus('awaiting_code', 'Nhập mã OTP.')),
            self::URL.'/login/code' => Http::response($this->museStatus('logged_in', 'Đã đăng nhập muse.ai.')),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('muse.login'), ['email' => 'me@example.com'])->assertSessionHas('status', 'Nhập mã OTP.');
        $this->actingAs($user)->post(route('muse.code'), ['code' => '123 456'])->assertSessionHas('status', 'Đã đăng nhập muse.ai.');

        Http::assertSent(fn (Request $request) => $request->url() === self::URL.'/login' && $request['email'] === 'me@example.com');
        Http::assertSent(fn (Request $request) => $request->url() === self::URL.'/login/code' && $request['code'] === '123 456');
    }

    public function test_service_error_is_shown_on_field()
    {
        Http::fake([self::URL.'/login/code' => Http::response(['message' => 'Sai mã'], 500)]);

        $this->actingAs(User::factory()->create())->post(route('muse.code'), ['code' => '1'])->assertSessionHasErrors(['code' => 'Sai mã']);
    }

    public function test_generator_starts_task_with_unique_code_and_polls_result()
    {
        $jpeg = UploadedFile::fake()->image('r.jpg')->getContent();
        Storage::disk('public')->put('models/m.jpg', 'model-bytes');
        Http::fake([
            self::URL.'/tasks' => Http::response(['state' => 'pending']),
            self::URL.'/tasks/*' => Http::sequence()
                ->push(['state' => 'pending'])
                ->push(['state' => 'done', 'result' => ['mime' => 'image/jpeg', 'base64' => base64_encode($jpeg), 'matched' => 'title']]),
        ]);

        $generator = app(ImageGenerator::class);
        $this->assertInstanceOf(MuseBrowserImageGenerator::class, $generator);
        $code = $generator->start(['models/m.jpg'], 'on a street');
        $other = $generator->start(['models/m.jpg'], 'on a street');

        $this->assertMatchesRegularExpression('/^VA-[A-Z0-9]{6}$/', $code);
        $this->assertNotSame($code, $other);
        Http::assertSent(fn (Request $request) => $request->url() === self::URL.'/tasks'
            && $request['code'] === $code
            && $request['kind'] === 'image'
            && str_contains($request['prompt'], "Use exactly \"{$code}\" as the title")
            && str_contains($request['prompt'], 'on a street')
            && $request['images'][0]['base64'] === base64_encode('model-bytes')
            && $request['timeout_ms'] === config('videoaff.muse_browser.timeout') * 1000);

        $this->assertNull($generator->poll($code, ['models/m.jpg']));
        $image = $generator->poll($code, ['models/m.jpg']);
        $this->assertSame('jpg', $image->extension);
        $this->assertSame($code, $image->requestId);
        $this->assertSame('muse.ai-browser', $image->provider);
    }

    public function test_poll_adopts_code_when_browser_service_restarted()
    {
        Http::fake([
            self::URL.'/tasks/*' => Http::response(['message' => 'Không có yêu cầu này.'], 404),
            self::URL.'/tasks' => Http::response(['state' => 'pending']),
        ]);

        $this->assertNull(app(ImageGenerator::class)->poll('VA-ABC123', []));
        Http::assertSent(fn (Request $request) => $request->url() === self::URL.'/tasks' && $request['code'] === 'VA-ABC123' && $request['adopt'] === true);
    }

    public function test_failed_task_is_retryable()
    {
        Http::fake([self::URL.'/tasks/*' => Http::response(['state' => 'failed', 'error' => 'Hết thời gian.'])]);

        try {
            app(ImageGenerator::class)->poll('VA-ABC123', []);
            $this->fail('Phải ném lỗi.');
        } catch (ImageGeneratorException $e) {
            $this->assertTrue($e->retryable);
            $this->assertStringContainsString('Hết thời gian', $e->getMessage());
        }
    }

    public function test_rejected_task_fails_without_retry_and_keeps_muse_reason()
    {
        $image = SceneImage::factory()->create(['status' => SceneImageStatus::Pending, 'provider_request_id' => 'VA-EI1NHZ']);
        Storage::disk('public')->put($image->scene->videoProject->fashionModel->photo_path, 'model-bytes');
        Http::fake([self::URL.'/tasks/*' => Http::response(['state' => 'rejected', 'error' => "I couldn't produce VA-EI1NHZ for this dress."])]);

        $job = (new GenerateSceneImageJob($image))->withFakeQueueInteractions();
        $job->handle(app(ImageGenerator::class), app(ScenePromptRewriter::class));

        $job->assertNotReleased();
        $job->assertFailed();
        Http::assertNotSent(fn (Request $request) => $request->url() === self::URL.'/tasks');
    }

    public function test_scene_image_job_releases_queue_while_waiting()
    {
        $jpeg = UploadedFile::fake()->image('r.jpg')->getContent();
        $image = SceneImage::factory()->create(['status' => SceneImageStatus::Pending]);
        Storage::disk('public')->put($image->scene->videoProject->fashionModel->photo_path, 'model-bytes');
        Http::fake([
            self::URL.'/tasks' => Http::response(['state' => 'pending']),
            self::URL.'/tasks/*' => Http::sequence()
                ->push(['state' => 'pending'])
                ->push(['state' => 'done', 'result' => ['mime' => 'image/jpeg', 'base64' => base64_encode($jpeg)]]),
        ]);

        // Lần 1: gửi yêu cầu, lưu mã, nhả hàng đợi.
        $job = (new GenerateSceneImageJob($image))->withFakeQueueInteractions();
        $job->handle(app(ImageGenerator::class), app(ScenePromptRewriter::class));
        $job->assertReleased(15);
        $code = $image->fresh()->provider_request_id;
        $this->assertMatchesRegularExpression('/^VA-[A-Z0-9]{6}$/', $code);

        // Lần 2: chưa xong, hỏi lại sau.
        $job = (new GenerateSceneImageJob($image->fresh()))->withFakeQueueInteractions();
        $job->handle(app(ImageGenerator::class), app(ScenePromptRewriter::class));
        $job->assertReleased(GenerateSceneImageJob::POLL_SECONDS);

        // Lần 3: xong, lưu ảnh.
        $job = (new GenerateSceneImageJob($image->fresh()))->withFakeQueueInteractions();
        $job->handle(app(ImageGenerator::class), app(ScenePromptRewriter::class));
        $job->assertNotReleased();
        $image->refresh();
        $this->assertSame(SceneImageStatus::Ready, $image->status);
        $this->assertSame($code, $image->provider_request_id);
        Storage::disk('public')->assertExists($image->path);
        Http::assertSentCount(3);
    }

    public function test_clip_job_sends_approved_image_and_saves_video()
    {
        $project = VideoProject::factory()->create(['motion_style' => 'outfit_spin', 'status' => VideoProjectStatus::Reviewing]);
        $scene = Scene::factory()->for($project)->create(['clip_status' => SceneClipStatus::Pending]);
        $approved = SceneImage::factory()->for($scene)->create(['status' => SceneImageStatus::Approved, 'path' => 'scenes/s.jpg']);
        Storage::disk('public')->put($approved->path, 'scene-bytes');
        $mp4 = file_get_contents(base_path('tests/Fixtures/tiny.mp4'));
        Http::fake([
            self::URL.'/tasks' => Http::response(['state' => 'pending']),
            self::URL.'/tasks/*' => Http::response(['state' => 'done', 'result' => ['mime' => 'video/mp4', 'base64' => base64_encode($mp4)]]),
        ]);

        $job = (new GenerateSceneClipJob($scene))->withFakeQueueInteractions();
        $job->handle(app(ClipGenerator::class));
        $job->assertReleased(30);
        $code = $scene->fresh()->clip_request_id;
        Http::assertSent(fn (Request $request) => $request->url() === self::URL.'/tasks'
            && $request['kind'] === 'video'
            && str_contains($request['prompt'], "Use exactly \"{$code}\" as the title of the video")
            && str_contains($request['prompt'], 'outfit-reveal spin')
            && $request['images'][0]['base64'] === base64_encode('scene-bytes'));

        $job = (new GenerateSceneClipJob($scene->fresh()))->withFakeQueueInteractions();
        $job->handle(app(ClipGenerator::class));
        $scene->refresh();
        $this->assertSame(SceneClipStatus::Ready, $scene->clip_status);
        $this->assertStringEndsWith('.mp4', $scene->clip_path);
        Storage::disk('public')->assertExists($scene->clip_path);
    }

    public function test_generator_rejects_reference_image_returned_as_result()
    {
        $jpeg = UploadedFile::fake()->image('p.jpg')->getContent();
        Storage::disk('public')->put('products/p.jpg', $jpeg);
        Http::fake([self::URL.'/tasks/*' => Http::response(['state' => 'done', 'result' => ['mime' => 'image/jpeg', 'base64' => base64_encode($jpeg)]])]);

        try {
            app(ImageGenerator::class)->poll('VA-ABC123', ['products/p.jpg']);
            $this->fail('Phải ném lỗi.');
        } catch (ImageGeneratorException $e) {
            $this->assertTrue($e->retryable);
            $this->assertStringContainsString('ảnh tham chiếu', $e->getMessage());
        }
    }

    public function test_generator_not_logged_in_is_not_retried()
    {
        Http::fake([self::URL.'/tasks' => Http::response(['message' => 'Chưa đăng nhập muse.ai.'], 409)]);

        try {
            app(ImageGenerator::class)->start([], 'x');
            $this->fail('Phải ném lỗi.');
        } catch (ImageGeneratorException $e) {
            $this->assertFalse($e->retryable);
            $this->assertSame('Chưa đăng nhập muse.ai.', $e->getMessage());
        }
    }

    public function test_generator_rejects_non_image_result()
    {
        Http::fake([self::URL.'/tasks/*' => Http::response(['state' => 'done', 'result' => ['mime' => 'text/html', 'base64' => base64_encode('<html>')]])]);

        $this->expectException(ImageGeneratorException::class);
        app(ImageGenerator::class)->poll('VA-ABC123', []);
    }
}
