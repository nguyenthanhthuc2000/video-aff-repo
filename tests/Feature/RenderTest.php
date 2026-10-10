<?php

namespace Tests\Feature;

use App\Enums\RenderStatus;
use App\Enums\SceneImageStatus;
use App\Enums\VideoProjectStatus;
use App\Jobs\RenderVideoJob;
use App\Models\Scene;
use App\Models\SceneImage;
use App\Models\User;
use App\Models\VideoProject;
use App\Services\Video\AssSubtitles;
use App\Services\Video\FfmpegVideoRenderer;
use App\Services\Video\RenderScene;
use App\Services\Video\RenderSpec;
use App\Services\Video\VideoRenderer;
use App\Services\Video\VideoRenderException;
use App\Support\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RenderTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<RenderSpec> */
    private array $rendered = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->app->instance(VideoRenderer::class, new class($this->rendered) implements VideoRenderer
        {
            public function __construct(private array &$rendered) {}

            public function render(RenderSpec $spec, ?callable $onProgress = null): void
            {
                $this->rendered[] = $spec;
                file_put_contents($spec->outputPath, 'mp4-bytes');
            }
        });
    }

    private function approvedProject(): VideoProject
    {
        $project = VideoProject::factory()->create([
            'status' => VideoProjectStatus::Reviewing,
            'script' => ['hooks' => ['Hook 0', 'Hook 1'], 'caption' => '', 'hashtags' => []],
        ]);
        $project->product->update(['details' => ['sizes' => 'S, M, L']]);

        foreach ([1, 2] as $position) {
            $scene = Scene::factory()->for($project)->create(['position' => $position, 'duration_ms' => 3000, 'on_screen_text' => "Chữ {$position}"]);
            $image = SceneImage::factory()->approved()->for($scene)->create();
            Media::disk()->put($image->path, 'image');
        }

        return $project;
    }

    public function test_render_creates_mp4_and_marks_done()
    {
        $project = $this->approvedProject();

        $this->actingAs($project->user)
            ->post(route('projects.renders.store', $project), ['hook' => 1])
            ->assertSessionHasNoErrors();

        $project->refresh();
        $render = $project->renders()->first();
        $this->assertSame(VideoProjectStatus::Done, $project->status);
        $this->assertSame(1, $project->selected_hook);
        $this->assertSame(RenderStatus::Done, $render->status);
        $this->assertSame(8500, $render->duration_ms);
        Storage::disk('public')->assertExists($render->path);

        $spec = $this->rendered[0];
        $this->assertSame('Hook 1', $spec->hook);
        $this->assertSame('Size: S, M, L', $spec->endCardSubtitle);
        $this->assertSame(['Chữ 1', 'Chữ 2'], array_map(fn ($s) => $s->title, $spec->scenes));
        $this->assertNull($spec->musicPath);
    }

    public function test_render_requires_every_scene_approved()
    {
        Queue::fake();
        $project = $this->approvedProject();
        $project->scenes[1]->images()->update(['status' => 'ready']);

        $this->actingAs($project->user)->post(route('projects.renders.store', $project), ['hook' => 0]);

        Queue::assertNothingPushed();
        $this->assertSame(VideoProjectStatus::Reviewing, $project->fresh()->status);
    }

    public function test_render_validates_hook_and_stores_music()
    {
        Queue::fake();
        $project = $this->approvedProject();

        $this->actingAs($project->user)
            ->post(route('projects.renders.store', $project), ['hook' => 5])
            ->assertSessionHasErrors('hook');

        $this->post(route('projects.renders.store', $project), [
            'hook' => 0,
            'music' => UploadedFile::fake()->create('nhac.mp3', 100, 'audio/mpeg'),
        ])->assertSessionHasNoErrors();

        $project->refresh();
        $this->assertNotNull($project->music);
        Storage::disk('public')->assertExists($project->music);
        $this->assertSame(VideoProjectStatus::Rendering, $project->status);
        Queue::assertPushed(RenderVideoJob::class);
    }

    public function test_renderer_error_marks_render_and_project_failed()
    {
        $this->app->instance(VideoRenderer::class, new class implements VideoRenderer
        {
            public function render(RenderSpec $spec, ?callable $onProgress = null): void
            {
                throw new VideoRenderException('ffmpeg lỗi: hỏng');
            }
        });
        $project = $this->approvedProject();

        try {
            $this->actingAs($project->user)->post(route('projects.renders.store', $project), ['hook' => 0]);
        } catch (VideoRenderException) {
            // Hàng đợi sync ném lại lỗi sau khi gọi failed().
        }

        $render = $project->renders()->first();
        $this->assertSame(RenderStatus::Failed, $render->status);
        $this->assertStringContainsString('ffmpeg lỗi: hỏng', $render->error);
        $this->assertSame(VideoProjectStatus::Failed, $project->fresh()->status);
    }

    public function test_download_is_limited_to_owner()
    {
        $project = $this->approvedProject();
        $this->actingAs($project->user)->post(route('projects.renders.store', $project), ['hook' => 0]);
        $render = $project->renders()->first();

        $this->get(route('renders.download', $render))->assertOk()->assertDownload("video-aff-{$project->id}-{$render->id}.mp4");
        $this->actingAs(User::factory()->create())->get(route('renders.download', $render))->assertForbidden();
    }

    public function test_ass_subtitles_use_hook_on_first_scene_and_escape_text()
    {
        $spec = new RenderSpec([
            new RenderScene('a.jpg', 3000, 'Chữ 1', "Dòng 1\nDòng {2}"),
            new RenderScene('b.jpg', 2500, 'Chữ 2', ''),
        ], 'Hook', '/tmp/out.mp4');

        $ass = AssSubtitles::build($spec);

        $this->assertStringContainsString('Dialogue: 0,0:00:00.00,0:00:03.00,Hook,,0,0,0,,{\fad(150,150)}Hook', $ass);
        $this->assertStringNotContainsString('Chữ 1', $ass);
        $this->assertStringContainsString('Dòng 1\NDòng (2)', $ass);
        $this->assertStringContainsString('0:00:03.00,0:00:05.50,Title', $ass);
        $this->assertStringContainsString('0:00:05.50,0:00:08.00,End', $ass);
        $this->assertSame('1:01:01.25', AssSubtitles::time(3661250));
    }

    public function test_render_without_text_has_no_overlays_and_no_end_card()
    {
        $spec = new RenderSpec([new RenderScene('a.jpg', 3000, 'Chữ 1', 'Lời 1'), new RenderScene('b.jpg', 2500, 'Chữ 2')], 'Hook', '/tmp/out.mp4', withText: false);
        $renderer = new FfmpegVideoRenderer('ffmpeg', resource_path('fonts'));

        $this->assertSame(5500, $spec->durationMs());
        $this->assertStringNotContainsString('Dialogue:', AssSubtitles::build($spec));
        $graph = $renderer->filterGraph($spec, '/tmp/subs.ass');
        $this->assertStringNotContainsString('ass=', $graph);
        $this->assertStringNotContainsString('boxblur', $graph);
        // 2 ảnh cảnh + âm thanh im lặng, không có ảnh cảnh kết.
        $this->assertSame(2, collect($renderer->command($spec, '/tmp/f.txt'))->filter(fn ($arg) => $arg === '-i')->count() - 1);
    }

    public function test_render_request_remembers_text_choice()
    {
        Queue::fake();
        $project = VideoProject::factory()->create(['status' => VideoProjectStatus::Reviewing, 'script' => ['hooks' => ['H']]]);
        $scene = Scene::factory()->for($project)->create();
        SceneImage::factory()->for($scene)->create(['status' => SceneImageStatus::Approved]);

        $this->actingAs($project->user)->post(route('projects.renders.store', $project), ['hook' => 0, 'with_text' => false])->assertRedirect();

        $this->assertFalse($project->fresh()->render_text);
        $this->assertFalse($project->renders()->first()->with_text);
    }

    public function test_ffmpeg_failure_raises_render_exception()
    {
        Process::fake(['*' => Process::result(errorOutput: 'Invalid filter', exitCode: 1)]);
        $dir = sys_get_temp_dir().'/render-test-'.uniqid();
        mkdir($dir);

        $this->expectException(VideoRenderException::class);
        $this->expectExceptionMessage('Invalid filter');

        (new FfmpegVideoRenderer('ffmpeg', resource_path('fonts')))
            ->render(new RenderSpec([new RenderScene('a.jpg', 1000)], 'Hook', "{$dir}/out.mp4"));
    }

    public function test_ffmpeg_renders_real_video()
    {
        if (! Process::run(['ffmpeg', '-version'])->successful()) {
            $this->markTestSkipped('ffmpeg không có trên máy.');
        }

        $dir = sys_get_temp_dir().'/render-test-'.uniqid();
        mkdir($dir);
        Process::run(['ffmpeg', '-loglevel', 'error', '-f', 'lavfi', '-i', 'color=c=pink:s=300x400', '-frames:v', '1', "{$dir}/a.png"])->throw();

        $progress = [];
        (new FfmpegVideoRenderer('ffmpeg', resource_path('fonts'), 270, 480))->render(new RenderSpec(
            [new RenderScene("{$dir}/a.png", 1000, 'Chữ', 'Lời'), new RenderScene("{$dir}/a.png", 1000)],
            'Hook tiếng Việt',
            "{$dir}/out.mp4",
            endCardMs: 1000,
        ), function (int $percent) use (&$progress) {
            $progress[] = $percent;
        });

        $this->assertNotEmpty($progress);
        $this->assertGreaterThanOrEqual(90, max($progress));
        $this->assertLessThanOrEqual(100, max($progress));

        $probe = Process::run(['ffprobe', '-v', 'error', '-show_entries', 'stream=codec_name,width,height', '-of', 'csv=p=0', "{$dir}/out.mp4"])->throw();
        $this->assertStringContainsString('h264,270,480', $probe->output());
        $this->assertStringContainsString('aac', $probe->output());
    }

    public function test_ffmpeg_renders_scene_clip_instead_of_image()
    {
        if (! Process::run(['ffmpeg', '-version'])->successful()) {
            $this->markTestSkipped('ffmpeg không có trên máy.');
        }

        $dir = sys_get_temp_dir().'/render-test-'.uniqid();
        mkdir($dir);
        Process::run(['ffmpeg', '-loglevel', 'error', '-f', 'lavfi', '-i', 'color=c=pink:s=300x400', '-frames:v', '1', "{$dir}/a.png"])->throw();
        // Clip ngang, có tiếng, ngắn hơn cảnh: phải được cắt khung dọc và lặp lại.
        Process::run([
            'ffmpeg', '-loglevel', 'error', '-f', 'lavfi', '-i', 'testsrc=s=320x240:r=24:d=0.6',
            '-f', 'lavfi', '-i', 'sine=d=0.6', '-shortest', '-pix_fmt', 'yuv420p', "{$dir}/clip.mp4",
        ])->throw();

        (new FfmpegVideoRenderer('ffmpeg', resource_path('fonts'), 270, 480))->render(new RenderSpec(
            [new RenderScene("{$dir}/a.png", 1500, 'Nhảy', clipPath: "{$dir}/clip.mp4"), new RenderScene("{$dir}/a.png", 1000)],
            'Hook',
            "{$dir}/out.mp4",
            endCardMs: 1000,
        ));

        $probe = Process::run(['ffprobe', '-v', 'error', '-show_entries', 'stream=codec_name,width,height:format=duration', '-of', 'csv=p=0', "{$dir}/out.mp4"])->throw();
        $this->assertStringContainsString('h264,270,480', $probe->output());
        $this->assertMatchesRegularExpression('/^3\.\d+/m', $probe->output());
    }

    public function test_render_job_passes_scene_clip_to_renderer()
    {
        $project = $this->approvedProject();
        Media::disk()->put('clips/x.mp4', 'clip');
        $project->scenes()->where('position', 1)->update(['clip_path' => 'clips/x.mp4']);

        $render = $project->renders()->create(['hook' => 0]);
        (new RenderVideoJob($render))->handle(app(VideoRenderer::class));

        $this->assertSame(RenderStatus::Done, $render->fresh()->status);
        $this->assertStringEndsWith('clip-1.mp4', (string) $this->rendered[0]->scenes[0]->clipPath);
        $this->assertNull($this->rendered[0]->scenes[1]->clipPath);
    }
}
