<?php

namespace Tests\Feature;

use App\Enums\RenderStatus;
use App\Enums\SceneImageStatus;
use App\Enums\VideoProjectStatus;
use App\Jobs\GenerateScriptJob;
use App\Models\Render;
use App\Models\Scene;
use App\Models\SceneImage;
use App\Models\VideoProject;
use App\Services\Video\RenderSpec;
use App\Services\Video\VideoRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AutopilotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->app->instance(VideoRenderer::class, new class implements VideoRenderer
        {
            public function render(RenderSpec $spec, ?callable $onProgress = null): void
            {
                $onProgress && $onProgress(50);
                file_put_contents($spec->outputPath, 'mp4');
            }
        });
    }

    public function test_autopilot_runs_script_images_and_render_in_background()
    {
        // Driver "fake" chạy được như API; queue sync trong test nên các job chạy nối tiếp.
        config(['videoaff.script_driver' => 'fake', 'videoaff.image_driver' => 'fake']);
        $project = VideoProject::factory()->create();
        Storage::disk('public')->put($project->fashionModel->photo_path, 'photo');

        $this->actingAs($project->user)->post(route('projects.autopilot', $project))->assertRedirect();

        $project->refresh();
        $this->assertSame(VideoProjectStatus::Done, $project->status, (string) $project->error);
        $this->assertFalse($project->autopilot);
        $this->assertTrue($project->allScenesApproved());
        $render = $project->renders()->sole();
        $this->assertSame(RenderStatus::Done, $render->status);
        $this->assertSame(100, $render->progress);
        $this->assertSame(['percent' => 100, 'label' => 'Đã xong'], $project->progress());
    }

    public function test_create_project_with_autopilot_starts_it()
    {
        Queue::fake();
        config(['videoaff.script_driver' => 'claude', 'videoaff.image_driver' => 'fal']);
        $existing = VideoProject::factory()->create();

        $this->actingAs($existing->user)->post(route('projects.store'), [
            'product_id' => $existing->product_id,
            'fashion_model_id' => $existing->fashion_model_id,
            'autopilot' => true,
        ]);

        $project = $existing->user->videoProjects()->latest('id')->first();
        $this->assertTrue($project->autopilot);
        $this->assertSame(VideoProjectStatus::Scripting, $project->status);
        Queue::assertPushed(GenerateScriptJob::class);
    }

    public function test_autopilot_is_blocked_without_api_keys()
    {
        Queue::fake();
        config(['videoaff.script_driver' => 'web', 'videoaff.image_driver' => 'web']);
        $project = VideoProject::factory()->create();

        $this->assertSame('Cần đặt CLAUDE_CODE_OAUTH_TOKEN hoặc ANTHROPIC_API_KEY (viết kịch bản) và FAL_KEY (tạo ảnh Muse) trong .env để chạy ngầm.', $project->autopilotBlocker());

        $this->actingAs($project->user)->post(route('projects.autopilot', $project));
        $this->assertFalse($project->fresh()->autopilot);
        Queue::assertNothingPushed();
    }

    public function test_autopilot_only_needs_keys_for_unfinished_steps()
    {
        config(['videoaff.script_driver' => 'web', 'videoaff.image_driver' => 'web']);
        $project = VideoProject::factory()->create(['script' => ['hooks' => ['H'], 'caption' => '', 'hashtags' => []], 'status' => VideoProjectStatus::Reviewing]);
        $scene = Scene::factory()->for($project)->create();
        SceneImage::factory()->approved()->for($scene)->create();

        $this->assertNull($project->autopilotBlocker());
    }

    public function test_progress_reflects_images_and_render()
    {
        $project = VideoProject::factory()->create(['status' => VideoProjectStatus::Imaging]);
        foreach ([SceneImageStatus::Ready, SceneImageStatus::Pending] as $i => $status) {
            SceneImage::factory()->for(Scene::factory()->for($project)->create(['position' => $i + 1]))->create(['status' => $status]);
        }
        $this->assertSame(['percent' => 45, 'label' => 'Đang tạo ảnh 1/2'], $project->progress());

        $project->update(['status' => VideoProjectStatus::Rendering]);
        Render::factory()->for($project)->create(['progress' => 50]);
        $this->assertSame(85, $project->progress()['percent']);
    }
}
