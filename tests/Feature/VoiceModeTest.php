<?php

namespace Tests\Feature;

use App\Enums\SceneClipStatus;
use App\Enums\SceneImageStatus;
use App\Enums\VideoProjectStatus;
use App\Enums\VoiceMode;
use App\Jobs\GenerateSceneClipJob;
use App\Models\AudioTrack;
use App\Models\FashionModel;
use App\Models\Product;
use App\Models\Scene;
use App\Models\SceneImage;
use App\Models\User;
use App\Models\VideoProject;
use App\Services\Audio\MusicAnalyzer;
use App\Services\Script\ScriptPrompt;
use App\Services\Video\ClipGenerator;
use App\Services\Video\FfmpegVideoRenderer;
use App\Services\Video\ReferenceClip;
use App\Services\Video\RenderScene;
use App\Services\Video\RenderSpec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VoiceModeTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'http://browser:3000';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['videoaff.image_driver' => 'browser', 'videoaff.muse_browser.url' => self::URL]);
        Http::fake([self::URL.'/tasks' => Http::response(['state' => 'pending'])]);
    }

    private function sceneFor(VideoProject $project, int $position = 1, string $voiceover = 'Váy này mặc mát lắm nè'): Scene
    {
        $scene = Scene::factory()->for($project)->create([
            'position' => $position,
            'duration_ms' => 1000,
            'voiceover' => $voiceover,
            'clip_status' => SceneClipStatus::Pending,
        ]);
        $image = SceneImage::factory()->for($scene)->create(['status' => SceneImageStatus::Approved, 'path' => "scenes/{$position}.jpg"]);
        Storage::disk('public')->put($image->path, 'scene-bytes');

        return $scene;
    }

    private function runClip(Scene $scene): void
    {
        $job = (new GenerateSceneClipJob($scene))->withFakeQueueInteractions();
        $job->handle(app(ClipGenerator::class), app(ReferenceClip::class), app(MusicAnalyzer::class));
        $job->assertReleased(30);
    }

    public function test_best_start_picks_loudest_window()
    {
        $levels = [...array_fill(0, 10, -60.0), ...array_fill(0, 5, -10.0), ...array_fill(0, 5, -60.0)];

        $this->assertSame(10, MusicAnalyzer::bestStart($levels, 5));
        $this->assertSame(0, MusicAnalyzer::bestStart($levels, 30));
        $this->assertSame(0, MusicAnalyzer::bestStart(array_fill(0, 20, -20.0), 5));
    }

    public function test_speaking_clip_says_the_scene_line_without_motion_style()
    {
        $project = VideoProject::factory()->create(['status' => VideoProjectStatus::Reviewing, 'voice_mode' => VoiceMode::Speak]);
        $scene = $this->sceneFor($project);
        $this->assertTrue($project->hasMotion());

        $this->runClip($scene);

        Http::assertSent(fn (Request $request) => count($request['images']) === 1
            && str_contains($request['prompt'], 'says exactly this line and nothing else: "Váy này mặc mát lắm nè"')
            && str_contains($request['prompt'], 'same voice in every part'));
        $this->assertStringContainsString('Video REVIEW', ScriptPrompt::userText($project));
    }

    public function test_music_only_clip_tells_model_not_to_talk()
    {
        $project = VideoProject::factory()->create(['status' => VideoProjectStatus::Reviewing, 'motion_style' => 'groove', 'motion_styles' => ['groove']]);
        $this->runClip($this->sceneFor($project));

        Http::assertSent(fn (Request $request) => str_contains($request['prompt'], 'does not talk or sing')
            && str_contains($request['prompt'], 'playful glances at the camera'));
        // Kịch bản: chỉ cười, đá mắt, làm dáng; không tả người mẫu đang nói.
        $this->assertStringContainsString('chỉ cười, đá mắt, làm dáng làm duyên', ScriptPrompt::userText($project));
        $this->assertStringNotContainsString('Video REVIEW', ScriptPrompt::userText($project));
    }

    public function test_singing_clip_attaches_its_part_of_the_song()
    {
        if (! Process::run(['ffmpeg', '-version'])->successful()) {
            $this->markTestSkipped('Cần ffmpeg.');
        }
        $project = VideoProject::factory()->create(['status' => VideoProjectStatus::Reviewing, 'voice_mode' => VoiceMode::Sing]);
        // Phân tích giọng hát lỗi: vẫn tạo clip, chỉ không có dòng thời gian lời.
        $track = AudioTrack::factory()->for($project->user)->create(['path' => 'audio/song.mp3', 'vocal_status' => 'failed']);
        Storage::disk('public')->put($track->path, file_get_contents(base_path('tests/Fixtures/tiny.mp3')));
        $project->update(['audio_track_id' => $track->id]);
        $this->sceneFor($project, 1, '');
        $second = $this->sceneFor($project, 2, '');

        $this->runClip($second);

        $this->assertSame(0, $project->fresh()->music_start_ms);
        Http::assertSent(fn (Request $request) => count($request['images']) === 2
            && $request['images'][1]['name'] === 'song-part.m4a'
            && str_contains($request['prompt'], 'She lip-syncs to it'));
        // Đoạn nhạc tạm đã gửi thì xoá.
        $this->assertSame([], Storage::disk('public')->allFiles('audio-parts'));
    }

    public function test_renderer_keeps_clip_voices_when_speaking_and_starts_music_at_chosen_part()
    {
        $renderer = new FfmpegVideoRenderer('ffmpeg', resource_path('fonts'));
        $scenes = [new RenderScene('/a.png', 2000, clipPath: '/c.mp4', clipHasAudio: true), new RenderScene('/a.png', 1000)];

        $speak = $renderer->filterGraph(new RenderSpec($scenes, '', '/o.mp4', musicPath: '/m.mp3', clipAudio: true), '/s.ass');
        $this->assertStringContainsString('[0:a]aresample=44100', $speak);
        $this->assertStringContainsString('anullsrc=r=44100:cl=stereo,atrim=0:1[a1]', $speak);
        $this->assertStringContainsString('volume=0.12', $speak);
        $this->assertStringContainsString('amix=inputs=2', $speak);

        $sing = $renderer->filterGraph(new RenderSpec($scenes, '', '/o.mp4', musicPath: '/m.mp3', musicVolume: 1.0, musicStartMs: 12000), '/s.ass');
        $this->assertStringContainsString('atrim=12:', $sing);
        $this->assertStringNotContainsString('[0:a]', $sing);
    }

    public function test_ffmpeg_renders_speaking_video_with_clip_audio()
    {
        if (! Process::run(['ffmpeg', '-version'])->successful()) {
            $this->markTestSkipped('Cần ffmpeg.');
        }
        $dir = sys_get_temp_dir().'/voice-test-'.uniqid();
        mkdir($dir);
        Process::run(['ffmpeg', '-loglevel', 'error', '-f', 'lavfi', '-i', 'color=c=pink:s=300x400', '-frames:v', '1', "{$dir}/a.png"])->throw();

        (new FfmpegVideoRenderer('ffmpeg', resource_path('fonts'), 270, 480))->render(new RenderSpec(
            [new RenderScene("{$dir}/a.png", 1500, 'Nói', 'Lời', base_path('tests/Fixtures/tiny-with-audio.mp4'), true), new RenderScene("{$dir}/a.png", 1000)],
            'Hook',
            "{$dir}/out.mp4",
            endCardMs: 500,
            musicPath: base_path('tests/Fixtures/tiny.mp3'),
            clipAudio: true,
        ));

        $probe = Process::run(['ffprobe', '-v', 'error', '-show_entries', 'stream=codec_type:format=duration', '-of', 'csv=p=0', "{$dir}/out.mp4"])->throw();
        $this->assertStringContainsString('audio', $probe->output());
        $this->assertMatchesRegularExpression('/^3\.\d+/m', $probe->output());
    }

    public function test_voice_mode_validation_on_create_and_change()
    {
        Queue::fake();
        $user = User::factory()->create();
        $product = Product::factory()->for($user)->create();
        $model = FashionModel::factory()->for($user)->create();
        $base = ['product_id' => $product->id, 'fashion_model_id' => $model->id];

        $this->actingAs($user)->post(route('projects.store'), [...$base, 'voice_mode' => 'sing'])->assertSessionHasErrors('voice_mode');
        $this->actingAs($user)->post(route('projects.store'), [...$base, 'voice_mode' => 'speak'])->assertSessionHasNoErrors();
        $project = $user->videoProjects()->latest('id')->first();
        $this->assertSame(VoiceMode::Speak, $project->voice_mode);

        $project->update(['music_start_ms' => 5000]);
        $this->actingAs($user)->put(route('projects.voice', $project), ['voice_mode' => 'sing'])->assertSessionHasErrors('voice_mode');
        $this->actingAs($user)->put(route('projects.voice', $project), ['voice_mode' => 'music'])->assertSessionHasNoErrors();
        $this->assertSame(VoiceMode::Music, $project->fresh()->voice_mode);
        $this->assertNull($project->fresh()->music_start_ms);

        $this->actingAs(User::factory()->create())->put(route('projects.voice', $project), ['voice_mode' => 'speak'])->assertForbidden();
    }
}
