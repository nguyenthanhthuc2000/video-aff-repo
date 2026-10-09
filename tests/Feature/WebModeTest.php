<?php

namespace Tests\Feature;

use App\Enums\SceneImageStatus;
use App\Enums\VideoProjectStatus;
use App\Models\Scene;
use App\Models\SceneImage;
use App\Models\User;
use App\Models\VideoProject;
use App\Services\Script\ScriptPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WebModeTest extends TestCase
{
    use RefreshDatabase;

    private const REPLY = <<<'TXT'
    Đây là kịch bản:
    ```json
    {
      "hooks": ["Hook 1", "Hook 2", "Hook 3"],
      "scenes": [
        {"visual": "Toàn thân", "on_screen_text": "Chữ 1", "voiceover": "Lời 1", "image_prompt": "full body", "duration_ms": 3000},
        {"visual": "Cận vải", "on_screen_text": "Chữ 2", "voiceover": "Lời 2", "image_prompt": "close up", "duration_ms": 2500}
      ],
      "caption": "Caption",
      "hashtags": ["#vay"]
    }
    ```
    TXT;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['videoaff.script_driver' => 'web', 'videoaff.image_driver' => 'web']);
    }

    public function test_web_mode_is_default_without_keys()
    {
        $config = require config_path('videoaff.php');

        // phpunit.xml ép driver "fake"; ở đây chỉ kiểm tra giá trị mặc định khi không có biến môi trường nào.
        $this->assertStringContainsString("(bool) env('ANTHROPIC_API_KEY') => 'claude'", file_get_contents(config_path('videoaff.php')));
        $this->assertStringContainsString("(bool) env('CLAUDE_CODE_OAUTH_TOKEN') => 'claude_cli'", file_get_contents(config_path('videoaff.php')));
        $this->assertStringContainsString("default => 'web'", file_get_contents(config_path('videoaff.php')));
        $this->assertStringContainsString("(env('FAL_KEY') ? 'fal' : 'web')", file_get_contents(config_path('videoaff.php')));
        $this->assertArrayHasKey('claude_web_url', $config);
    }

    public function test_show_page_exposes_prompt_for_claude_web()
    {
        $project = VideoProject::factory()->create();

        $this->actingAs($project->user)
            ->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('project.script_mode', 'web')
                ->where('project.image_mode', 'web')
                ->where('project.script_prompt', fn ($prompt) => str_contains($prompt, $project->product->name) && str_contains($prompt, '"hooks"'))
                ->has('project.references', 1));
    }

    public function test_generate_buttons_do_nothing_in_web_mode()
    {
        Queue::fake();
        $project = VideoProject::factory()->create();

        $this->actingAs($project->user)->post(route('projects.script.store', $project));

        Queue::assertNothingPushed();
        $this->assertSame(VideoProjectStatus::Draft, $project->fresh()->status);
    }

    public function test_import_claude_reply_creates_scenes()
    {
        $project = VideoProject::factory()->create();

        $this->actingAs($project->user)
            ->post(route('projects.script.import', $project), ['reply' => self::REPLY])
            ->assertSessionHasNoErrors();

        $project->refresh();
        $this->assertSame(VideoProjectStatus::ScriptReady, $project->status);
        $this->assertSame(['Hook 1', 'Hook 2', 'Hook 3'], $project->script['hooks']);
        $this->assertSame(['Chữ 1', 'Chữ 2'], $project->scenes->pluck('on_screen_text')->all());
    }

    public function test_import_rejects_bad_reply()
    {
        $project = VideoProject::factory()->create();

        $this->actingAs($project->user)
            ->post(route('projects.script.import', $project), ['reply' => 'Xin lỗi, tôi không làm được.'])
            ->assertSessionHasErrors(['reply' => 'Không tìm thấy JSON trong câu trả lời của Claude.']);

        $this->post(route('projects.script.import', $project), ['reply' => '{"hooks": ["a"], "scenes": []}'])
            ->assertSessionHasErrors('reply');

        $this->assertNull($project->fresh()->script);
    }

    public function test_upload_muse_image_approves_it_and_replaces_previous()
    {
        $project = VideoProject::factory()->create(['status' => VideoProjectStatus::ScriptReady, 'script' => ['hooks' => ['h']]]);
        $scene = Scene::factory()->for($project)->create();
        $old = SceneImage::factory()->approved()->for($scene)->create();

        $this->actingAs($project->user)
            ->post(route('scenes.image.upload', $scene), ['image' => UploadedFile::fake()->image('muse.jpg', 900, 1600)])
            ->assertSessionHasNoErrors();

        $image = $scene->fresh()->latestImage;
        $this->assertSame(SceneImageStatus::Approved, $image->status);
        $this->assertSame('meta.ai-web', $image->provider);
        Storage::disk('public')->assertExists($image->path);
        $this->assertSame(SceneImageStatus::Rejected, $old->fresh()->status);
        $this->assertSame(VideoProjectStatus::Reviewing, $project->fresh()->status);
        $this->assertTrue($project->allScenesApproved());
    }

    public function test_upload_validates_and_authorizes()
    {
        $project = VideoProject::factory()->create(['status' => VideoProjectStatus::ScriptReady]);
        $scene = Scene::factory()->for($project)->create();

        $this->actingAs($project->user)
            ->post(route('scenes.image.upload', $scene), ['image' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('image');

        $this->actingAs(User::factory()->create())
            ->post(route('scenes.image.upload', $scene), ['image' => UploadedFile::fake()->image('a.jpg')])
            ->assertForbidden();
    }

    public function test_web_prompt_parser_accepts_plain_json()
    {
        $script = ScriptPrompt::parseWebReply('{"hooks":["a"],"scenes":[{"visual":"v","image_prompt":"p","duration_ms":3000}]}');

        $this->assertSame(['a'], $script['hooks']);
        $this->assertSame(3000, $script['scenes'][0]['duration_ms']);
    }
}
