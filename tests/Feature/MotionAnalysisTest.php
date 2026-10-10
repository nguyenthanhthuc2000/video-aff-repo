<?php

namespace Tests\Feature;

use App\Enums\SceneClipStatus;
use App\Enums\SceneImageStatus;
use App\Enums\VideoProjectStatus;
use App\Jobs\AnalyzeSampleVideoJob;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MotionAnalysisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function needsFfmpeg(): void
    {
        if (! Process::run(['ffmpeg', '-version'])->successful()) {
            $this->markTestSkipped('Cần ffmpeg.');
        }
    }

    private function sample(User $user, string $fixture = 'tiny.mp4', array $attributes = []): SampleVideo
    {
        $video = SampleVideo::factory()->for($user)->create(['path' => "sample-videos/{$user->id}/{$fixture}", 'duration_ms' => 1000, ...$attributes]);
        Storage::disk('public')->put($video->path, file_get_contents(base_path("tests/Fixtures/{$fixture}")));

        return $video;
    }

    public function test_upload_queues_motion_analysis_unless_claude_is_web_only()
    {
        Queue::fake();
        $user = User::factory()->create();
        $file = fn () => new UploadedFile(base_path('tests/Fixtures/tiny.mp4'), 'trend.mp4', 'video/mp4', null, true);

        $this->actingAs($user)->post(route('sample-videos.store'), ['video' => $file(), 'rights' => true]);
        $this->assertSame('pending', $user->sampleVideos()->first()->analysis_status);
        Queue::assertPushed(AnalyzeSampleVideoJob::class, 1);

        config(['videoaff.script_driver' => 'web']);
        $this->actingAs($user)->post(route('sample-videos.store'), ['video' => $file(), 'rights' => true]);
        $this->assertNull($user->sampleVideos()->latest('id')->first()->analysis_status);
        Queue::assertPushed(AnalyzeSampleVideoJob::class, 1);
    }

    public function test_analysis_job_stores_motion_timeline_used_by_copy_prompt()
    {
        $this->needsFfmpeg();
        $project = VideoProject::factory()->create();
        $video = $this->sample($project->user);

        AnalyzeSampleVideoJob::dispatchSync($video);

        $video->refresh();
        $this->assertSame('ready', $video->analysis_status);
        $this->assertCount(2, $video->motion_analysis['steps']);
        $this->assertStringStartsWith('Motion timeline to reproduce exactly: 0.0–0.5s: steps left and right', $video->motionTimeline());

        $project->update(['sample_video_id' => $video->id]);
        $scene = Scene::factory()->for($project)->create();
        $prompt = MotionCopy::prompt($scene);
        $this->assertStringContainsString('Motion timeline to reproduce exactly', $prompt);
        $this->assertStringContainsString('Camera: full body, static camera', $prompt);
        $this->assertStringContainsString('same camera framing and movement', $prompt);
    }

    public function test_clip_job_waits_for_pending_analysis()
    {
        config(['videoaff.image_driver' => 'browser']);
        $project = VideoProject::factory()->create(['status' => VideoProjectStatus::Reviewing]);
        $project->update(['sample_video_id' => $this->sample($project->user, attributes: ['analysis_status' => 'pending'])->id]);
        $scene = Scene::factory()->for($project)->create(['clip_status' => SceneClipStatus::Pending]);
        SceneImage::factory()->for($scene)->create(['status' => SceneImageStatus::Approved]);

        $job = (new GenerateSceneClipJob($scene))->withFakeQueueInteractions();
        $job->handle(app(ClipGenerator::class), app(ReferenceClip::class));

        $job->assertReleased(20);
        $this->assertNull($scene->fresh()->clip_request_id);
    }

    public function test_music_like_sample_video_is_extracted_once_into_library()
    {
        $this->needsFfmpeg();
        $user = User::factory()->create();
        $withAudio = $this->sample($user, 'tiny-with-audio.mp4');
        $silent = $this->sample($user);

        $this->actingAs($user)->post(route('sample-videos.audio', $silent))->assertSessionHasErrors('sample_video');
        $this->assertFalse($silent->fresh()->has_audio);

        $this->actingAs($user)->post(route('sample-videos.audio', $withAudio))->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('sample-videos.audio', $withAudio));
        $track = $user->audioTracks()->sole();
        $this->assertStringStartsWith('Nhạc video mẫu:', $track->name);
        $this->assertTrue($track->isPlayable());
        $this->assertTrue($withAudio->fresh()->audioTrack->is($track));

        // Tạo video chọn "nhạc như video mẫu".
        Queue::fake();
        $product = Product::factory()->for($user)->create();
        $model = FashionModel::factory()->for($user)->create();
        $this->actingAs($user)->post(route('projects.store'), [
            'product_id' => $product->id, 'fashion_model_id' => $model->id, 'sample_video_id' => $withAudio->id, 'use_sample_audio' => true,
        ])->assertSessionHasNoErrors();
        $this->assertTrue($user->videoProjects()->sole()->audioTrack->is($track));

        $this->actingAs($user)->post(route('projects.store'), [
            'product_id' => $product->id, 'fashion_model_id' => $model->id, 'use_sample_audio' => true,
        ])->assertSessionHasErrors('audio_track_id');
    }
}
