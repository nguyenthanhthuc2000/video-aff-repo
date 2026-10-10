<?php

namespace Tests\Feature;

use App\Enums\SceneClipStatus;
use App\Enums\SceneImageStatus;
use App\Enums\VideoProjectStatus;
use App\Jobs\GenerateSceneClipJob;
use App\Models\FashionModel;
use App\Models\Product;
use App\Models\SampleVideo;
use App\Models\Scene;
use App\Models\SceneImage;
use App\Models\User;
use App\Models\VideoProject;
use App\Services\Video\ClipGenerator;
use App\Services\Video\ReferenceClip;
use App\Support\MotionCopy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MotionCopyTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'http://browser:3000';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['videoaff.image_driver' => 'browser', 'videoaff.muse_browser.url' => self::URL]);
    }

    private function sample(User $user): SampleVideo
    {
        $video = SampleVideo::factory()->for($user)->create(['path' => "sample-videos/{$user->id}/trend.mp4"]);
        Storage::disk('public')->put($video->path, file_get_contents(base_path('tests/Fixtures/tiny.mp4')));

        return $video;
    }

    public function test_project_reference_drives_clip_generation_with_attached_video()
    {
        if (! Process::run(['ffmpeg', '-version'])->successful()) {
            $this->markTestSkipped('Cần ffmpeg.');
        }

        $project = VideoProject::factory()->create(['status' => VideoProjectStatus::Reviewing]);
        $project->update(['sample_video_id' => $this->sample($project->user)->id]);
        $scene = Scene::factory()->for($project)->create(['clip_status' => SceneClipStatus::Pending]);
        $image = SceneImage::factory()->for($scene)->create(['status' => SceneImageStatus::Approved, 'path' => 'scenes/s.jpg']);
        Storage::disk('public')->put($image->path, 'scene-bytes');
        Http::fake([self::URL.'/tasks' => Http::response(['state' => 'pending'])]);

        $this->assertTrue($project->fresh()->hasMotion());

        $job = (new GenerateSceneClipJob($scene))->withFakeQueueInteractions();
        $job->handle(app(ClipGenerator::class), app(ReferenceClip::class));
        $job->assertReleased(30);

        Http::assertSent(fn (Request $request) => $request->url() === self::URL.'/tasks'
            && $request['kind'] === 'video'
            && count($request['images']) === 2
            && $request['images'][0]['base64'] === base64_encode('scene-bytes')
            && $request['images'][1]['name'] === 'reference-motion.mp4'
            && str_contains($request['prompt'], 'exactly the same movements as the person in the reference video')
            && str_contains($request['prompt'], 'do not copy the person, clothes, background'));

        // Đoạn video mẫu của cảnh được lưu lại để dùng lại khi tạo lại clip.
        $segment = $scene->fresh()->motionSegment();
        $sample = $project->sampleVideo;
        $reference = sprintf('sample-videos/%d/ref-%d-%d-%d.mp4', $sample->user_id, $sample->id, $segment['start'] * 1000, $segment['length'] * 1000);
        Storage::disk('public')->assertExists($reference);
        $this->assertSame([720], [Process::run(['ffprobe', '-v', 'error', '-show_entries', 'stream=height', '-of', 'csv=p=0', Storage::disk('public')->path($reference)])->output() + 0]);
    }

    public function test_scene_reference_overrides_project_and_prompt_is_shown()
    {
        $project = VideoProject::factory()->create(['status' => VideoProjectStatus::Reviewing, 'motion_styles' => ['catwalk'], 'motion_style' => 'catwalk']);
        $scenes = Scene::factory()->count(2)->for($project)->sequence(['position' => 1], ['position' => 2])->create();
        $own = $this->sample($project->user);

        $this->actingAs($project->user)->put(route('scenes.motion-reference', $scenes[1]), ['sample_video_id' => $own->id])->assertSessionHasNoErrors();

        $this->assertNull($scenes[0]->fresh()->motionReference());
        $this->assertTrue($scenes[1]->fresh()->motionReference()->is($own));

        // Người dùng thường không thấy prompt (tạo clip tự động); admin thấy.
        $this->actingAs($project->user)->get(route('projects.show', $project))->assertInertia(fn (Assert $page) => $page
            ->missing('project.scenes.1.motion_prompt'));
        $project->user->forceFill(['is_admin' => true])->save();

        $this->actingAs($project->user->fresh())->get(route('projects.show', $project))->assertInertia(fn (Assert $page) => $page
            ->where('project.has_motion', true)
            ->where('project.scenes.0.motion_reference', null)
            ->where('project.scenes.1.motion_reference.id', $own->id)
            ->where('project.scenes.1.motion_prompt', fn ($prompt) => str_contains($prompt, 'reference video'))
            ->where('project.scenes.0.motion_prompt', fn ($prompt) => str_contains($prompt, 'runway catwalk'))
            ->has('sampleVideos', 1));
    }

    public function test_references_must_belong_to_user()
    {
        $project = VideoProject::factory()->create();
        $scene = Scene::factory()->for($project)->create();
        $foreign = SampleVideo::factory()->create();

        $this->actingAs($project->user)->put(route('projects.motion-reference', $project), ['sample_video_id' => $foreign->id])->assertSessionHasErrors('sample_video_id');
        $this->actingAs($project->user)->put(route('scenes.motion-reference', $scene), ['sample_video_id' => $foreign->id])->assertSessionHasErrors('sample_video_id');
        $this->actingAs(User::factory()->create())->put(route('projects.motion-reference', $project), ['sample_video_id' => null])->assertForbidden();

        $this->assertFalse($project->fresh()->hasMotion());
    }

    public function test_project_can_be_created_with_sample_video()
    {
        $user = User::factory()->create();
        $product = Product::factory()->for($user)->create();
        $model = FashionModel::factory()->for($user)->create();
        $video = SampleVideo::factory()->for($user)->create();

        $this->actingAs($user)->post(route('projects.store'), [
            'product_id' => $product->id, 'fashion_model_id' => $model->id, 'sample_video_id' => $video->id,
        ])->assertSessionHasNoErrors();

        $project = $user->videoProjects()->sole();
        $this->assertTrue($project->sampleVideo->is($video));
        $this->assertTrue($project->wantsClips());
    }

    public function test_scenes_copy_consecutive_segments_of_the_sample_video()
    {
        $project = VideoProject::factory()->create();
        $sample = SampleVideo::factory()->for($project->user)->create([
            'duration_ms' => 17000,
            'motion_analysis' => ['summary' => '', 'tempo' => '', 'framing' => '', 'steps' => [
                ['start' => 0, 'end' => 4, 'motion' => 'points at the camera'],
                ['start' => 4, 'end' => 9, 'motion' => 'hands on hips'],
                ['start' => 9, 'end' => 17, 'motion' => 'waves goodbye'],
            ]],
        ]);
        $project->update(['sample_video_id' => $sample->id]);
        $scenes = collect(range(1, 5))->map(fn ($position) => Scene::factory()->for($project)->create(['position' => $position, 'duration_ms' => 4200]));

        $segments = $scenes->map(fn (Scene $scene) => $scene->fresh()->motionSegment())->all();

        $this->assertSame([
            ['start' => 0.0, 'length' => 4.7],
            ['start' => 4.2, 'length' => 4.7],
            ['start' => 8.4, 'length' => 4.7],
            ['start' => 12.3, 'length' => 4.7], // làm nốt phần cuối điệu nhảy
            ['start' => 0.0, 'length' => 4.7], // hết video mẫu thì quay lại từ đầu
        ], $segments);

        // Prompt của cảnh 2 chỉ có các nhịp trong đoạn 4,2–8,9s, tính lại từ 0.
        $prompt = MotionCopy::prompt($scenes[1]->fresh());
        $this->assertStringContainsString('4.7 seconds long', $prompt);
        $this->assertStringContainsString('0.0–4.7s: hands on hips', $prompt);
        $this->assertStringNotContainsString('points at the camera', $prompt);
        $this->assertStringNotContainsString('waves goodbye', $prompt);
    }
}
