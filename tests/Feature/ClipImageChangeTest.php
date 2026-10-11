<?php

namespace Tests\Feature;

use App\Enums\SceneClipStatus;
use App\Enums\SceneImageStatus;
use App\Enums\VideoProjectStatus;
use App\Jobs\GenerateSceneClipJob;
use App\Models\Scene;
use App\Models\SceneImage;
use App\Models\VideoProject;
use App\Services\Video\ClipGenerator;
use App\Services\Video\ReferenceClip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Đổi ảnh cảnh trong lúc clip đang tạo (project 79): clip cũ bị bỏ, không báo lỗi "cần ảnh đã duyệt". */
class ClipImageChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();
        config(['videoaff.image_driver' => 'browser', 'videoaff.muse_browser.url' => 'http://browser:3000']);
    }

    private function sceneWithPendingClip(bool $oneTake = false): Scene
    {
        $project = VideoProject::factory()->create(['status' => VideoProjectStatus::Reviewing, 'one_take' => $oneTake, 'motion_style' => 'groove', 'motion_styles' => ['groove']]);
        $scene = Scene::factory()->for($project)->create(['position' => 1, 'clip_status' => SceneClipStatus::Pending, 'clip_request_id' => 'VA-ABCDEF']);
        SceneImage::factory()->for($scene)->create(['status' => SceneImageStatus::Approved, 'path' => 'scenes/1.jpg']);

        return $scene;
    }

    public function test_regenerating_image_discards_the_clip_in_progress()
    {
        $scene = $this->sceneWithPendingClip(oneTake: true);
        $next = Scene::factory()->for($scene->videoProject)->create(['position' => 2, 'clip_status' => SceneClipStatus::Ready, 'clip_path' => 'clips/2.mp4']);
        $frame = SceneImage::factory()->for($next)->create(['status' => SceneImageStatus::Approved, 'provider' => 'one_take']);

        $this->actingAs($scene->videoProject->user)->post(route('scenes.image.regenerate', $scene))->assertSessionHasNoErrors();

        $this->assertNull($scene->fresh()->clip_status);
        $this->assertNull($scene->fresh()->clip_request_id);
        // Quay liền: nhịp sau nối từ clip này nên bỏ theo, kể cả khung mở đầu.
        $this->assertNull($next->fresh()->clip_path);
        $this->assertSame(SceneImageStatus::Rejected, $frame->fresh()->status);

        // Job đang hỏi kết quả clip cũ tự dừng, không đánh dấu lỗi.
        $job = (new GenerateSceneClipJob($scene->fresh()))->withFakeQueueInteractions();
        $job->handle(app(ClipGenerator::class), app(ReferenceClip::class));
        $job->assertNotFailed();
        $this->assertNull($scene->fresh()->clip_error);
    }

    public function test_polling_does_not_need_the_approved_image()
    {
        Http::fake(['http://browser:3000/tasks/*' => Http::response(['state' => 'pending'])]);
        $scene = $this->sceneWithPendingClip();
        $scene->images()->update(['status' => SceneImageStatus::Pending]);

        $job = (new GenerateSceneClipJob($scene->fresh()))->withFakeQueueInteractions();
        $job->handle(app(ClipGenerator::class), app(ReferenceClip::class));

        $job->assertNotFailed();
        $job->assertReleased(GenerateSceneClipJob::POLL_SECONDS);
    }

    public function test_approving_a_different_image_discards_old_clip()
    {
        $scene = $this->sceneWithPendingClip();
        $scene->update(['clip_status' => SceneClipStatus::Ready, 'clip_path' => 'clips/1.mp4', 'clip_request_id' => null]);
        $other = SceneImage::factory()->for($scene)->create(['status' => SceneImageStatus::Ready]);

        $this->actingAs($scene->videoProject->user)->post(route('scene-images.approve', $other));

        $this->assertNull($scene->fresh()->clip_path);
        $this->assertSame(SceneImageStatus::Approved, $other->fresh()->status);
    }

    public function test_restarting_autopilot_retries_the_failed_one_take_clip()
    {
        $scene = $this->sceneWithPendingClip(oneTake: true);
        $scene->update(['clip_status' => SceneClipStatus::Failed, 'clip_error' => 'x', 'clip_request_id' => null]);
        $project = $scene->videoProject;
        $project->update(['script' => ['hooks' => ['H'], 'caption' => '', 'hashtags' => []]]);

        $this->actingAs($project->user)->post(route('projects.autopilot', $project));

        Queue::assertPushed(GenerateSceneClipJob::class, 1);
        $this->assertSame(SceneClipStatus::Pending, $scene->fresh()->clip_status);
        $this->assertNotSame(VideoProjectStatus::Failed, $project->fresh()->status);
    }
}
