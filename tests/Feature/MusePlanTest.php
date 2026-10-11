<?php

namespace Tests\Feature;

use App\Enums\SceneClipStatus;
use App\Enums\SceneImageStatus;
use App\Enums\VideoProjectStatus;
use App\Enums\VoiceMode;
use App\Jobs\GenerateSceneClipJob;
use App\Jobs\GenerateScriptJob;
use App\Jobs\PlanWithMuseJob;
use App\Models\MuseAnalysis;
use App\Models\SampleVideo;
use App\Models\Scene;
use App\Models\SceneImage;
use App\Models\VideoProject;
use App\Services\Image\MuseBrowserClient;
use App\Services\Script\ScriptPrompt;
use App\Services\Video\ClipGenerator;
use App\Services\Video\ReferenceClip;
use App\Support\MusePlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Muse xem video mẫu + nghe nhạc rồi lên phân cảnh trước khi viết kịch bản; shot dài 10–30 giây làm từ nhiều đoạn nối liền. */
class MusePlanTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'http://browser:3000';

    private const ANSWER = <<<'TEXT'
    Đây là phân cảnh:
    ```json
    {"music_start": 12.5, "tempo_bpm": 128, "mood": "vui", "summary": "Hai shot theo video mẫu.",
     "song_lines": [{"start": 12.5, "end": 15, "text": "Bao lâu dựng xây"}],
     "shots": [
       {"duration": 6, "camera": "full body, static", "action": "She sways and turns", "expression": "smiling", "product_focus": "front of the dress",
        "sample_start": 0, "sample_end": 6, "mouth": [{"start": 0, "end": 2.5, "state": "sing", "words": "Bao lâu dựng xây"}, {"start": 2.5, "end": 6, "state": "closed", "words": ""}]},
       {"duration": 45, "camera": "slow push in", "action": "She walks toward the camera", "expression": "confident", "product_focus": "fabric in motion",
        "sample_start": 6, "sample_end": 4, "mouth": []}
     ]}
    ```
    TEXT;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['videoaff.image_driver' => 'browser', 'videoaff.muse_browser.url' => self::URL]);
    }

    private function project(array $attributes = []): VideoProject
    {
        $project = VideoProject::factory()->create(['status' => VideoProjectStatus::Draft, 'voice_mode' => VoiceMode::Sing, ...$attributes]);
        $sample = SampleVideo::factory()->for($project->user)->create();
        Storage::disk('public')->put($sample->path, 'sample-video-bytes');
        $project->update(['sample_video_id' => $sample->id]);

        return $project->fresh();
    }

    public function test_parse_reads_json_from_reply_and_clamps_shots()
    {
        $plan = MusePlan::parse(self::ANSWER, $this->project());

        $this->assertSame(12.5, $plan['music_start']);
        $this->assertCount(2, $plan['shots']);
        $this->assertSame(30.0, $plan['shots'][1]['duration']);
        // Đoạn video mẫu ngược (6 → 4) thì bỏ.
        $this->assertNull($plan['shots'][1]['sample_start']);
        $this->assertSame('sing', $plan['shots'][0]['mouth'][0]['state']);

        $this->expectException(\RuntimeException::class);
        MusePlan::parse('Xin lỗi, tôi không xem được video.', $this->project());
    }

    public function test_script_waits_for_muse_plan_then_follows_it()
    {
        Queue::fake();
        $project = $this->project();
        $project->queueScript();

        Queue::assertPushed(PlanWithMuseJob::class);
        Queue::assertNotPushed(GenerateScriptJob::class);
        $this->assertSame('pending', $project->fresh()->muse_plan_status);
        $this->assertStringContainsString('Muse đang xem video mẫu', $project->fresh()->progress()['label']);

        Http::fake([
            self::URL.'/tasks' => Http::response(['state' => 'pending']),
            self::URL.'/tasks/*' => Http::sequence()
                ->push(['state' => 'pending'])
                ->push(['state' => 'done', 'result' => ['text' => self::ANSWER]]),
        ]);

        $job = (new PlanWithMuseJob($project))->withFakeQueueInteractions();
        $job->handle(app(MuseBrowserClient::class));
        $job->assertReleased(60);
        $code = $project->fresh()->muse_plan_request_id;
        Http::assertSent(fn (Request $request) => $request->url() === self::URL.'/tasks'
            && $request['kind'] === 'text'
            && $request['code'] === $code
            && str_contains($request['prompt'], "Request code: {$code}")
            && str_contains($request['prompt'], 'LIP-SYNCS')
            && $request['images'][0]['name'] === 'sample-video.mp4');

        $job = (new PlanWithMuseJob($project))->withFakeQueueInteractions();
        $job->handle(app(MuseBrowserClient::class));
        $job->assertReleased(PlanWithMuseJob::POLL_SECONDS);

        (new PlanWithMuseJob($project))->withFakeQueueInteractions()->handle(app(MuseBrowserClient::class));
        $project->refresh();
        $this->assertSame('ready', $project->muse_plan_status);
        $this->assertSame(12500, $project->music_start_ms);
        Queue::assertPushed(GenerateScriptJob::class);

        // Lưu kho: prompt, câu trả lời gốc, kết quả và đầu vào (để xây bộ skill viết prompt).
        $archived = MuseAnalysis::sole();
        $this->assertSame([$code, 'ready', $project->id], [$archived->request_code, $archived->status, $archived->video_project_id]);
        $this->assertStringContainsString('LIP-SYNCS', $archived->prompt);
        $this->assertSame(self::ANSWER, $archived->reply);
        $this->assertSame(12.5, $archived->result['music_start']);
        $this->assertSame('sing', $archived->context['voice_mode']);
        $this->assertSame(['sample-video.mp4'], $archived->context['attachments']);
        $this->artisan('muse:export', ['path' => storage_path('framework/testing/muse.jsonl')])->assertSuccessful();
        $this->assertStringContainsString('"music_start":12.5', file_get_contents(storage_path('framework/testing/muse.jsonl')));
        @unlink(storage_path('framework/testing/muse.jsonl'));

        // Kịch bản theo đúng phân cảnh: số cảnh, thời lượng, hành động.
        $this->assertSame(2, $project->videoSettings()['scenes']);
        $this->assertSame(36, $project->videoSettings()['seconds']);
        $prompt = ScriptPrompt::userText($project);
        $this->assertStringContainsString('PHÂN CẢNH MUSE', $prompt);
        $this->assertStringContainsString('Cảnh 2 (30000 ms): She walks toward the camera', $prompt);

        $project->applyScript([
            'hooks' => ['H'], 'caption' => '', 'hashtags' => [],
            'scenes' => [
                ['visual' => 'a', 'on_screen_text' => '', 'voiceover' => '', 'image_prompt' => 'p', 'duration_ms' => 3000],
                ['visual' => 'b', 'on_screen_text' => '', 'voiceover' => '', 'image_prompt' => 'p', 'duration_ms' => 3000],
            ],
        ]);
        $this->assertSame([6000, 30000], $project->scenes()->pluck('duration_ms')->all());

        // Đổi kiểu tiếng: lần viết kịch bản sau Muse lên lại phân cảnh.
        $this->actingAs($project->user)->put(route('projects.voice', $project), ['voice_mode' => 'music']);
        $this->assertNull($project->fresh()->muse_plan_status);
    }

    public function test_failed_plan_still_writes_script()
    {
        Queue::fake();
        $project = $this->project(['status' => VideoProjectStatus::Scripting, 'muse_plan_status' => 'pending']);

        (new PlanWithMuseJob($project))->failed(new \RuntimeException('hết giờ'));

        $this->assertSame('failed', $project->fresh()->muse_plan_status);
        $this->assertSame(0, MuseAnalysis::count());
        $this->assertStringContainsString('hết giờ', $project->fresh()->muse_plan_error);
        Queue::assertPushed(GenerateScriptJob::class);
    }

    public function test_clip_lines_follow_muse_shot()
    {
        $project = $this->project(['status' => VideoProjectStatus::Reviewing, 'voice_mode' => VoiceMode::Music]);
        $project->update(['muse_plan_status' => 'ready', 'muse_plan' => MusePlan::parse(self::ANSWER, $project)]);
        $scene = Scene::factory()->for($project)->create(['position' => 1, 'duration_ms' => 6000]);
        Scene::factory()->for($project)->create(['position' => 2, 'duration_ms' => 30000]);

        $timeline = MusePlan::mouthTimeline($scene);
        $this->assertStringContainsString('0.0–2.5s: singing "Bao lâu dựng xây"', $timeline);
        $this->assertStringContainsString('2.5–6.0s: no singing', $timeline);
        $this->assertStringContainsString('She sways and turns', MusePlan::shotLine($scene));
        $this->assertEquals(['start' => 0.0, 'end' => 6.0], MusePlan::sampleRange($scene));
        $this->assertEquals(['start' => 0.0, 'length' => 6.0], $scene->motionSegment());
    }

    public function test_long_shot_is_made_in_parts_and_joined()
    {
        if (! Process::run(['ffmpeg', '-version'])->successful()) {
            $this->markTestSkipped('Cần ffmpeg.');
        }
        $project = VideoProject::factory()->create(['status' => VideoProjectStatus::Reviewing, 'motion_style' => 'groove', 'motion_styles' => ['groove']]);
        $scene = Scene::factory()->for($project)->create(['position' => 1, 'duration_ms' => 25000, 'clip_status' => SceneClipStatus::Pending]);
        $image = SceneImage::factory()->for($scene)->create(['status' => SceneImageStatus::Approved, 'path' => 'scenes/1.jpg']);
        Storage::disk('public')->put($image->path, 'scene-bytes');
        $this->assertCount(3, MusePlan::parts($scene));

        $mp4 = base64_encode(file_get_contents(base_path('tests/Fixtures/tiny.mp4')));
        Http::fake([
            self::URL.'/tasks' => Http::response(['state' => 'pending']),
            self::URL.'/tasks/*' => Http::response(['state' => 'done', 'result' => ['mime' => 'video/mp4', 'base64' => $mp4]]),
        ]);

        foreach (range(1, 3) as $part) {
            $job = (new GenerateSceneClipJob($scene->fresh()))->withFakeQueueInteractions();
            $job->handle(app(ClipGenerator::class), app(ReferenceClip::class));
            $job->assertReleased(30);
            $this->assertStringContainsString("part {$part} of 3 of ONE continuous 25-second shot", (string) $scene->fresh()->clip_prompt);

            $job = (new GenerateSceneClipJob($scene->fresh()))->withFakeQueueInteractions();
            $job->handle(app(ClipGenerator::class), app(ReferenceClip::class));
            if ($part < 3) {
                $job->assertReleased(5);
                $this->assertCount($part, $scene->fresh()->clip_parts);
                $this->assertSame(SceneClipStatus::Pending, $scene->fresh()->clip_status);
            }
        }

        // Đoạn 2, 3 bắt đầu từ khung cuối đoạn trước (không phải ảnh cảnh).
        Http::assertSent(fn (Request $request) => $request->url() === self::URL.'/tasks' && $request['images'][0]['name'] === "{$scene->id}-part1.jpg");
        $scene->refresh();
        $this->assertSame(SceneClipStatus::Ready, $scene->clip_status);
        $this->assertNull($scene->clip_parts);
        $this->assertStringContainsString('-long-', $scene->clip_path);
        Storage::disk('public')->assertExists($scene->clip_path);
        // Chỉ còn clip đã ghép (đoạn lẻ và khung tạm đã xoá).
        $this->assertSame([$scene->clip_path], Storage::disk('public')->allFiles('clips'));
        $this->assertSame([], Storage::disk('public')->allFiles('frames'));
    }
}
