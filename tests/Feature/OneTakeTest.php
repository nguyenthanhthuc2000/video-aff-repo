<?php

namespace Tests\Feature;

use App\Enums\SceneClipStatus;
use App\Enums\SceneImageStatus;
use App\Enums\VideoProjectStatus;
use App\Jobs\GenerateSceneClipJob;
use App\Models\FashionModel;
use App\Models\Product;
use App\Models\Scene;
use App\Models\SceneImage;
use App\Models\User;
use App\Models\VideoProject;
use App\Services\Script\ScriptPrompt;
use App\Support\OneTake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OneTakeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['videoaff.image_driver' => 'browser']);
    }

    /** @return array{VideoProject, Collection<int, Scene>} */
    private function project(int $scenes = 3): array
    {
        $project = VideoProject::factory()->create(['one_take' => true, 'status' => VideoProjectStatus::Reviewing]);
        $list = collect(range(1, $scenes))->map(fn ($i) => Scene::factory()->for($project)->create(['position' => $i, 'duration_ms' => 5000, 'visual' => "Nhịp {$i}"]));

        return [$project, $list];
    }

    public function test_one_take_uses_beats_and_only_first_scene_gets_an_image()
    {
        [$project, $scenes] = $this->project();

        $settings = $project->videoSettings();
        $this->assertSame(0, $settings['seconds'] % VideoProject::ONE_TAKE_BEAT_SECONDS);
        $this->assertStringContainsString('Một cảnh quay liền', $settings['reason']);
        $this->assertStringContainsString('MỘT CẢNH QUAY LIỀN', ScriptPrompt::userText($project));
        $this->assertTrue($project->hasMotion());

        $this->assertSame([$scenes[0]->id], $project->scenesMissingImages()->pluck('id')->all());
        $this->assertFalse($project->imagesReady());

        SceneImage::factory()->for($scenes[0])->create(['status' => SceneImageStatus::Approved]);
        $this->assertTrue($project->imagesReady());
        $this->assertFalse($project->allScenesApproved());
        // Clip tạo lần lượt: chỉ nhịp đầu.
        $this->assertSame([$scenes[0]->id], $project->scenesMissingClips()->pluck('id')->all());

        $prompt = OneTake::prompt($scenes[0]->fresh());
        $this->assertStringContainsString('single continuous take', $prompt);
        $this->assertStringContainsString('beginning of the shot', $prompt);
        $this->assertStringContainsString('exact last frame of the previous part', OneTake::prompt($scenes[1]->fresh()));
    }

    public function test_clip_last_frame_becomes_next_scene_image_and_later_clips_restart()
    {
        if (! Process::run(['ffmpeg', '-version'])->successful()) {
            $this->markTestSkipped('Cần ffmpeg.');
        }
        Queue::fake();
        [$project, $scenes] = $this->project();
        Storage::disk('public')->put('clips/a.mp4', file_get_contents(base_path('tests/Fixtures/tiny.mp4')));
        $scenes[0]->update(['clip_path' => 'clips/a.mp4', 'clip_status' => SceneClipStatus::Ready, 'clip_request_id' => 'VA-AAAAAA']);
        $scenes[2]->update(['clip_path' => 'clips/old.mp4', 'clip_status' => SceneClipStatus::Ready]);
        $old = SceneImage::factory()->for($scenes[1])->create(['status' => SceneImageStatus::Approved]);

        OneTake::continueAfter($scenes[0]->fresh());

        $this->assertLessThan(5000, $scenes[0]->fresh()->duration_ms); // độ dài thật của clip
        $frame = $scenes[1]->fresh()->approvedImage;
        $this->assertSame('one_take', $frame->provider);
        Storage::disk('public')->assertExists($frame->path);
        $this->assertSame(SceneImageStatus::Rejected, $old->fresh()->status);
        $this->assertSame(SceneClipStatus::Pending, $scenes[1]->fresh()->clip_status);
        $this->assertNull($scenes[2]->fresh()->clip_path);
        Queue::assertPushed(GenerateSceneClipJob::class, fn ($job) => $job->scene->is($scenes[1]));
    }

    public function test_later_scene_images_cannot_be_regenerated()
    {
        [$project, $scenes] = $this->project(2);
        SceneImage::factory()->for($scenes[1])->create(['status' => SceneImageStatus::Approved]);

        $this->actingAs($project->user)->post(route('scenes.image.regenerate', $scenes[1]))
            ->assertSessionHas('status', fn ($status) => str_contains($status, 'khung cuối clip cảnh trước'));
    }

    public function test_create_one_take_project_validation()
    {
        Queue::fake();
        $user = User::factory()->create();
        $product = Product::factory()->for($user)->create();
        $second = Product::factory()->for($user)->create();
        $model = FashionModel::factory()->for($user)->create();
        $base = ['product_id' => $product->id, 'fashion_model_id' => $model->id, 'one_take' => true];

        $this->actingAs($user)->post(route('projects.store'), [...$base, 'outfits' => [['product_id' => $product->id], ['product_id' => $second->id]]])
            ->assertSessionHasErrors('one_take');

        config(['videoaff.image_driver' => 'fake']);
        $this->actingAs($user)->post(route('projects.store'), $base)->assertSessionHasErrors('one_take');

        config(['videoaff.image_driver' => 'browser']);
        $this->actingAs($user)->post(route('projects.store'), $base)->assertSessionHasNoErrors();
        $this->assertTrue($user->videoProjects()->sole()->one_take);
    }
}
