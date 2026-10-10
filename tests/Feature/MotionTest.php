<?php

namespace Tests\Feature;

use App\Enums\MotionStyle;
use App\Enums\SceneClipStatus;
use App\Enums\SceneImageStatus;
use App\Enums\VideoProjectStatus;
use App\Jobs\GenerateSceneClipJob;
use App\Jobs\RenderVideoJob;
use App\Models\FashionModel;
use App\Models\Product;
use App\Models\Scene;
use App\Models\SceneImage;
use App\Models\User;
use App\Models\VideoProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MotionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_project_can_be_created_with_motion_style()
    {
        $project = VideoProject::factory()->create();
        $user = $project->user;

        $this->actingAs($user)->post(route('projects.store'), [
            'product_id' => $project->product_id,
            'fashion_model_id' => $project->fashion_model_id,
            'motion_style' => 'tiktok_dance',
        ])->assertRedirect();

        $created = $user->videoProjects()->latest('id')->first();
        $this->assertNotSame($project->id, $created->id);
        $this->assertSame(MotionStyle::TiktokDance, $created->motion_style);
    }

    public function test_motion_style_is_optional_and_validated()
    {
        $project = VideoProject::factory()->create();
        $data = ['product_id' => $project->product_id, 'fashion_model_id' => $project->fashion_model_id];

        $this->actingAs($project->user)->post(route('projects.store'), [...$data, 'motion_style' => 'moonwalk'])
            ->assertSessionHasErrors('motion_style');

        $this->actingAs($project->user)->post(route('projects.store'), $data)->assertRedirect();
        $this->assertNull($project->user->videoProjects()->latest('id')->first()->motion_style);
    }

    public function test_show_exposes_motion_prompt_and_models_for_new_video()
    {
        $project = VideoProject::factory()->create(['motion_style' => 'outfit_spin', 'status' => VideoProjectStatus::Reviewing]);
        Scene::factory()->for($project)->create(['position' => 1, 'visual' => 'Đứng giữa phố']);
        FashionModel::factory()->create(['user_id' => $project->user_id, 'consent_at' => null]);

        $this->actingAs($project->user)->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('project.motion_style', 'outfit_spin')
                ->where('project.motion_style_label', 'Xoay người khoe đồ')
                ->where('project.scenes.0.motion_prompt', fn ($prompt) => str_contains($prompt, 'outfit-reveal spin') && str_contains($prompt, 'Đứng giữa phố'))
                ->where('project.scenes.0.clip_url', null)
                // Chỉ người mẫu đã xác nhận đồng ý.
                ->has('fashionModels', FashionModel::where('user_id', $project->user_id)->whereNotNull('consent_at')->count()));
    }

    public function test_upload_and_remove_scene_clip()
    {
        $project = VideoProject::factory()->create(['motion_style' => 'catwalk', 'status' => VideoProjectStatus::Reviewing]);
        $scene = Scene::factory()->for($project)->create(['position' => 1]);

        $this->actingAs($project->user)
            ->post(route('scenes.clip.store', $scene), ['clip' => UploadedFile::fake()->create('a.mp4', 500, 'video/mp4')])
            ->assertSessionHasNoErrors();
        $first = $scene->fresh()->clip_path;
        Storage::disk('public')->assertExists($first);

        $this->actingAs($project->user)
            ->post(route('scenes.clip.store', $scene), ['clip' => UploadedFile::fake()->create('b.mp4', 500, 'video/mp4')]);
        Storage::disk('public')->assertMissing($first);

        $this->actingAs($project->user)->delete(route('scenes.clip.destroy', $scene));
        $this->assertNull($scene->fresh()->clip_path);
    }

    public function test_clip_upload_validates_type_and_owner()
    {
        $scene = Scene::factory()->create();

        $this->actingAs($scene->videoProject->user)
            ->post(route('scenes.clip.store', $scene), ['clip' => UploadedFile::fake()->image('a.jpg')])
            ->assertSessionHasErrors('clip');

        $this->actingAs(User::factory()->create())
            ->post(route('scenes.clip.store', $scene), ['clip' => UploadedFile::fake()->create('a.mp4', 10, 'video/mp4')])
            ->assertForbidden();
    }

    public function test_autopilot_waits_for_muse_clips_before_render()
    {
        Queue::fake();
        config(['videoaff.image_driver' => 'browser']);
        $project = VideoProject::factory()->create(['motion_style' => 'outfit_spin', 'status' => VideoProjectStatus::Reviewing, 'autopilot' => true, 'script' => ['hooks' => ['H'], 'caption' => '', 'hashtags' => []]]);
        $scenes = Scene::factory()->count(2)->for($project)->sequence(['position' => 1], ['position' => 2])->create();
        $scenes->each(fn ($scene) => SceneImage::factory()->for($scene)->create(['status' => SceneImageStatus::Approved]));

        $project->advanceAutopilot();

        Queue::assertPushed(GenerateSceneClipJob::class, 2);
        Queue::assertNotPushed(RenderVideoJob::class);
        $this->assertTrue($project->clipsPending());
        $this->assertStringContainsString('clip chuyển động 0/2', $project->progress()['label']);

        // Một clip xong, một clip lỗi: cảnh lỗi dùng ảnh tĩnh, xuất video.
        $scenes[0]->update(['clip_status' => SceneClipStatus::Ready, 'clip_path' => 'clips/a.mp4']);
        $scenes[1]->update(['clip_status' => SceneClipStatus::Failed, 'clip_error' => 'x']);
        $project->advanceAutopilot();

        Queue::assertPushed(RenderVideoJob::class);
        Queue::assertPushed(GenerateSceneClipJob::class, 2);
    }

    public function test_generate_all_clips_queues_scenes_with_approved_images()
    {
        Queue::fake();
        config(['videoaff.image_driver' => 'browser']);
        $project = VideoProject::factory()->create(['motion_style' => 'catwalk', 'status' => VideoProjectStatus::Reviewing]);
        [$approved, $failed, $noImage, $withClip] = Scene::factory()->count(4)->for($project)->sequence(fn ($s) => ['position' => $s->index + 1])->create();
        foreach ([$approved, $failed, $withClip] as $scene) {
            SceneImage::factory()->for($scene)->create(['status' => SceneImageStatus::Approved]);
        }
        $failed->update(['clip_status' => SceneClipStatus::Failed]);
        $withClip->update(['clip_path' => 'clips/x.mp4']);

        $this->actingAs($project->user)->post(route('projects.clips.store', $project))->assertSessionHas('status', 'AI đang tạo 2 clip chuyển động...');

        Queue::assertPushed(GenerateSceneClipJob::class, 2);
        $this->assertSame(SceneClipStatus::Pending, $approved->fresh()->clip_status);
        $this->assertSame(SceneClipStatus::Pending, $failed->fresh()->clip_status);
        $this->assertNull($noImage->fresh()->clip_status);

        $this->actingAs($project->user)->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page->where('project.is_busy', true)->where('project.clips_available', true));
    }

    public function test_clip_generation_needs_browser_driver_and_owner()
    {
        Queue::fake();
        $project = VideoProject::factory()->create(['motion_style' => 'catwalk', 'status' => VideoProjectStatus::Reviewing]);
        $scene = Scene::factory()->for($project)->create();
        SceneImage::factory()->for($scene)->create(['status' => SceneImageStatus::Approved]);

        config(['videoaff.image_driver' => 'fal']);
        $this->actingAs($project->user)->post(route('scenes.clip.generate', $scene))->assertSessionHas('status', fn ($s) => str_contains($s, 'chưa sẵn sàng'));

        config(['videoaff.image_driver' => 'browser']);
        $this->actingAs(User::factory()->create())->post(route('scenes.clip.generate', $scene))->assertForbidden();
        $this->actingAs($project->user)->post(route('scenes.clip.generate', $scene))->assertSessionHas('status', 'AI đang tạo clip cảnh 1...');

        Queue::assertPushed(GenerateSceneClipJob::class, 1);
    }

    public function test_multiple_motion_styles_rotate_across_scenes()
    {
        $user = User::factory()->create();
        $product = Product::factory()->for($user)->create();
        $model = FashionModel::factory()->for($user)->create();

        $this->actingAs($user)->post(route('projects.store'), [
            'product_id' => $product->id,
            'fashion_model_id' => $model->id,
            'motion_styles' => ['outfit_spin', 'catwalk'],
        ])->assertSessionHasNoErrors();

        $project = $user->videoProjects()->sole();
        $this->assertSame(MotionStyle::OutfitSpin, $project->motion_style);
        $this->assertSame([MotionStyle::OutfitSpin, MotionStyle::Catwalk], $project->motionStyles());
        $scenes = Scene::factory()->count(3)->for($project)->sequence(fn ($s) => ['position' => $s->index + 1])->create();
        $this->assertSame(
            [MotionStyle::OutfitSpin, MotionStyle::Catwalk, MotionStyle::OutfitSpin],
            $scenes->map(fn ($scene) => $project->motionStyleFor($scene))->all(),
        );

        $this->actingAs($user)->post(route('projects.store'), [
            'product_id' => $product->id,
            'fashion_model_id' => $model->id,
            'motion_styles' => ['catwalk', 'moonwalk'],
        ])->assertSessionHasErrors('motion_styles.1');
    }

    public function test_motion_prompt_asks_for_modest_version()
    {
        $scene = Scene::factory()->make(['visual' => 'Đi dạo phố']);

        $prompt = MotionStyle::TiktokDance->prompt($scene);

        $this->assertStringContainsString('not sexy', $prompt);
        $this->assertStringContainsString('instead of declining', $prompt);
        $this->assertStringNotContainsString('hip sway', $prompt);
    }
}
