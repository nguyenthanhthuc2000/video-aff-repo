<?php

namespace Tests\Feature;

use App\Enums\MotionStyle;
use App\Enums\VideoProjectStatus;
use App\Jobs\GenerateSceneImageJob;
use App\Models\AiUsageLog;
use App\Models\Render;
use App\Models\Scene;
use App\Models\SceneImage;
use App\Models\User;
use App\Models\VideoProject;
use App\Services\Image\ImageGenerator;
use App\Services\Image\Prompt\ScenePromptRewriter;
use App\Services\Script\ScriptPrompt;
use App\Support\CreditPricing;
use App\Support\ProductFidelity;
use App\Support\ProjectCredits;
use App\Support\ProjectTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PricingAndTimelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_prompts_forbid_changing_the_product()
    {
        $scene = Scene::factory()->create();

        $this->assertStringContainsString(ProductFidelity::IMAGE, GenerateSceneImageJob::scenePrompt($scene));
        $this->assertStringContainsString(ProductFidelity::VIDEO, MotionStyle::Catwalk->prompt($scene));
        $this->assertStringContainsString(ProductFidelity::SCRIPT_RULE, ScriptPrompt::SYSTEM);
        $this->assertStringContainsString('Never make the product itself more covered', ProductFidelity::IMAGE);
    }

    public function test_prompts_are_hidden_from_regular_users_but_shown_to_admins()
    {
        $scene = Scene::factory()->create();
        $project = $scene->videoProject;
        $project->update(['status' => VideoProjectStatus::Reviewing]);

        $this->actingAs($project->user)->get(route('projects.show', $project))->assertInertia(fn (Assert $page) => $page
            ->missing('project.scenes.0.image_prompt')
            ->missing('project.scenes.0.full_prompt')
            ->has('credits.total'));

        // Người dùng lưu kịch bản không gửi prompt: giữ prompt cũ.
        $project->update(['script' => ['hooks' => ['H'], 'caption' => 'C', 'hashtags' => []], 'status' => VideoProjectStatus::ScriptReady]);
        $before = $scene->image_prompt;
        $this->actingAs($project->user)->put(route('projects.script.update', $project), [
            'hooks' => ['H mới'], 'selected_hook' => 0, 'caption' => 'C', 'hashtags' => [],
            'scenes' => [['id' => $scene->id, 'visual' => 'V mới', 'on_screen_text' => 'T', 'voiceover' => 'L', 'duration_ms' => 3000, 'image_prompt' => 'hack']],
        ])->assertSessionHasNoErrors();
        $this->assertSame($before, $scene->fresh()->image_prompt);
        $this->assertSame('V mới', $scene->fresh()->visual);

        $admin = User::factory()->admin()->create();
        $project->user->forceFill(['is_admin' => true])->save();
        $this->actingAs($project->user->fresh())->get(route('projects.show', $project))->assertInertia(fn (Assert $page) => $page
            ->where('project.scenes.0.image_prompt', $before)
            ->has('project.scenes.0.full_prompt'));
        $this->assertNotNull($admin);
    }

    public function test_ai_call_credits_use_model_markup_and_minimum()
    {
        config(['videoaff.claude.model' => 'claude-sonnet-5-5']);
        // $0.05 × 26.000đ = 1.300đ; Sonnet +200% = 3.900 Credits.
        $this->assertSame(3900, CreditPricing::forAiCall(0.05, 'claude-sonnet-5-5'));
        // Opus +150%: 1.300 × 2,5 = 3.250.
        $this->assertSame(3250, CreditPricing::forAiCall(0.05, 'claude-opus-5-5'));
        // Lượt rất nhỏ vẫn tối thiểu 100 Credits.
        $this->assertSame(100, CreditPricing::forAiCall(0.0001, 'claude-haiku-5-5'));

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->put(route('admin.credit-pricing.update'), [
            'markup' => ['opus' => 100, 'sonnet' => 100, 'haiku' => 100],
            'min_ai_call' => 0,
            'signup_bonus' => 30000,
            'steps' => ['image' => 500, 'clip_style' => 2000, 'clip_copy' => 3000, 'render' => 900, 'render_long' => 1200],
        ])->assertSessionHasNoErrors();
        $this->assertSame(2600, CreditPricing::forAiCall(0.05, 'claude-sonnet-5-5'));
        $this->assertSame(500, CreditPricing::settings()['steps']['image']);

        $this->actingAs($admin)->get(route('admin.credit-pricing.show'))->assertInertia(fn (Assert $page) => $page
            ->component('admin/credit-pricing')->has('actions', 4)->where('settings.markup.opus', 100));
        $this->actingAs(User::factory()->create())->get(route('admin.credit-pricing.show'))->assertForbidden();
    }

    public function test_project_credits_count_ai_images_clips_and_renders()
    {
        $project = VideoProject::factory()->create();
        AiUsageLog::factory()->create(['video_project_id' => $project->id, 'action' => 'script', 'model' => 'claude-sonnet-5-5', 'cost_usd' => 0.05]);
        AiUsageLog::factory()->create(['video_project_id' => $project->id, 'action' => 'script', 'model' => 'claude-sonnet-5-5', 'cost_usd' => 0.05, 'success' => false]);
        $scene = Scene::factory()->for($project)->create(['clip_status' => 'ready', 'clip_request_id' => 'VA-ABC123']);
        SceneImage::factory()->for($scene)->count(2)->create();
        SceneImage::factory()->for($scene)->create(['status' => 'failed']);
        SceneImage::factory()->for($scene)->create(['provider' => 'meta.ai-web']);
        Render::factory()->for($project)->create(['status' => 'done', 'duration_ms' => 20000]);

        $credits = ProjectCredits::forProjects(collect([$project]))[$project->id];

        // Kịch bản 3.900 (lượt lỗi không tính) + 2 ảnh × 800 + 1 clip 2.500 + xuất video 1.000.
        $this->assertSame(3900 + 1600 + 2500 + 1000, $credits['total']);
        $this->assertSame(['script', 'images', 'clips', 'renders'], array_column($credits['steps'], 'key'));

        $this->actingAs($project->user)->get(route('projects.index'))->assertInertia(fn (Assert $page) => $page->where("credits.{$project->id}.total", 9000));
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('creditRates.image', 800)->has('creditRates.script'));
    }

    public function test_timeline_and_admin_detail_page()
    {
        $this->travelTo(now()->startOfMinute());
        $project = VideoProject::factory()->create(['created_at' => now()]);
        $this->travel(30)->seconds();
        $scene = Scene::factory()->for($project)->create();
        SceneImage::factory()->for($scene)->create(['created_at' => now(), 'completed_at' => now()->addSeconds(90), 'prompt' => 'FULL PROMPT']);
        $this->travel(5)->minutes();
        Render::factory()->for($project)->create(['status' => 'done', 'created_at' => now(), 'completed_at' => now()->addSeconds(20)]);

        $timeline = ProjectTimeline::forProjects(collect([$project]))[$project->id];
        $this->assertTrue($timeline['done']);
        $this->assertSame(30000, $timeline['steps']['script']['duration_ms']);
        $this->assertSame(90000, $timeline['steps']['images']['duration_ms']);
        $this->assertSame(20000, $timeline['steps']['render']['duration_ms']);
        $this->assertSame((30 + 300 + 20) * 1000, $timeline['total_ms']);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('admin.projects.show', $project))->assertInertia(fn (Assert $page) => $page
            ->component('admin/project-show')
            ->where('scenes.0.images.0.prompt', 'FULL PROMPT')
            ->where('scenes.0.images.0.duration_ms', 90000)
            ->where('timeline.total_ms', 350000)
            ->has('credits.total'));
        $this->actingAs($admin)->get(route('admin.projects.index'))->assertInertia(fn (Assert $page) => $page
            ->where('timeStats.count', 1)->where('timeStats.avg_ms', 350000)->where('projects.data.0.total_ms', 350000));
        $this->actingAs($project->user)->get(route('admin.projects.show', $project))->assertForbidden();
    }

    public function test_image_job_records_prompt_and_completion_time()
    {
        $image = SceneImage::factory()->create(['status' => 'pending', 'path' => null]);
        Storage::disk('public')->put($image->scene->videoProject->fashionModel->photo_path, 'model');

        (new GenerateSceneImageJob($image))->withFakeQueueInteractions()->handle(app(ImageGenerator::class), app(ScenePromptRewriter::class));

        $image->refresh();
        $this->assertStringContainsString(ProductFidelity::IMAGE, (string) $image->prompt);
        $this->assertNotNull($image->completed_at);
    }
}
