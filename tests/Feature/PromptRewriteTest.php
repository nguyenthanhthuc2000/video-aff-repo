<?php

namespace Tests\Feature;

use App\Enums\SceneImageStatus;
use App\Enums\VideoProjectStatus;
use App\Jobs\GenerateSceneImageJob;
use App\Models\Scene;
use App\Models\SceneImage;
use App\Models\VideoProject;
use App\Services\Image\ImageGenerator;
use App\Services\Image\MuseBrowserImageGenerator;
use App\Services\Image\Prompt\RewritePrompt;
use App\Services\Image\Prompt\RuleScenePromptRewriter;
use App\Services\Image\Prompt\ScenePromptRewriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PromptRewriteTest extends TestCase
{
    use RefreshDatabase;

    private const SEXY = 'On a tree-lined city sidewalk, bright morning sun. The woman walks toward the camera, the dress slit opening with each step. Full-body low-angle tracking shot. Vertical 9:16.';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function rejectedScene(): Scene
    {
        $project = VideoProject::factory()->create(['status' => VideoProjectStatus::Reviewing]);
        $scene = Scene::factory()->for($project)->create(['image_prompt' => self::SEXY]);
        SceneImage::factory()->for($scene)->create([
            'status' => SceneImageStatus::Failed,
            'error' => MuseBrowserImageGenerator::REJECTED.' Bấm Tạo lại... Muse trả lời: I can\'t make this one — the sidewalk shot keeps not going through.',
        ]);

        return $scene;
    }

    public function test_regenerate_after_rejection_passes_muse_reply_to_job()
    {
        Queue::fake();
        $scene = $this->rejectedScene();

        $this->actingAs($scene->videoProject->user)
            ->post(route('scenes.image.regenerate', $scene))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Muse đã từ chối prompt cũ'));

        Queue::assertPushed(GenerateSceneImageJob::class, fn ($job) => str_contains((string) $job->rewriteReason, 'sidewalk shot'));
    }

    public function test_regenerate_without_rejection_asks_for_a_new_variation()
    {
        Queue::fake();
        $project = VideoProject::factory()->create(['status' => VideoProjectStatus::Reviewing]);
        $scene = Scene::factory()->for($project)->create();
        SceneImage::factory()->for($scene)->create(['status' => SceneImageStatus::Ready]);

        $this->actingAs($project->user)->post(route('scenes.image.regenerate', $scene))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'viết prompt mới'));

        Queue::assertPushed(GenerateSceneImageJob::class, fn ($job) => $job->rewriteReason === RewritePrompt::VARIATION);
        $this->assertStringContainsString('muốn một ảnh khác', RewritePrompt::userText($scene, RewritePrompt::VARIATION));
    }

    public function test_user_edited_prompt_is_used_as_is()
    {
        Queue::fake();
        $scene = $this->rejectedScene();

        $this->actingAs($scene->videoProject->user)->post(route('scenes.image.regenerate', $scene), ['image_prompt' => 'A calm studio shot, standing. Vertical 9:16.']);

        $this->assertSame('A calm studio shot, standing. Vertical 9:16.', $scene->fresh()->image_prompt);
        Queue::assertPushed(GenerateSceneImageJob::class, fn ($job) => $job->rewriteReason === null);
    }

    public function test_job_rewrites_prompt_once_before_generating()
    {
        $scene = $this->rejectedScene();
        Storage::disk('public')->put($scene->videoProject->fashionModel->photo_path, 'photo');
        $this->app->instance(ScenePromptRewriter::class, new class implements ScenePromptRewriter
        {
            public int $calls = 0;

            public function rewrite(Scene $scene, string $reason): string
            {
                $this->calls++;

                return 'A tidy sidewalk with small shops, morning light. The woman stands naturally, full-body, eye-level camera. Vertical 9:16.';
            }
        });
        $image = $scene->images()->create();

        GenerateSceneImageJob::dispatchSync($image, 'the sidewalk shot keeps not going through');
        GenerateSceneImageJob::dispatchSync($image, 'the sidewalk shot keeps not going through');

        $this->assertStringStartsWith('A tidy sidewalk', $scene->fresh()->image_prompt);
        $this->assertSame(1, app(ScenePromptRewriter::class)->calls);
        $this->assertSame(SceneImageStatus::Ready, $image->fresh()->status);
    }

    public function test_rule_rewriter_drops_suggestive_sentences()
    {
        $scene = Scene::factory()->make(['image_prompt' => self::SEXY]);

        $prompt = (new RuleScenePromptRewriter)->rewrite($scene, '');

        $this->assertStringContainsString('city sidewalk', $prompt);
        $this->assertStringNotContainsString('slit', $prompt);
        $this->assertStringNotContainsString('low-angle', $prompt);
        $this->assertStringContainsString('modest', $prompt);

        $macro = (new RuleScenePromptRewriter)->rewrite(Scene::factory()->make(['image_prompt' => 'Extreme close-up of the lace bodice on her chest.']), '');
        $this->assertStringContainsString('mannequin', $macro);
    }

    public function test_muse_message_marks_request_as_independent()
    {
        $this->assertStringContainsString('independent request', MuseBrowserImageGenerator::message('VA-ABC123', 'x'));
        $this->assertInstanceOf(ImageGenerator::class, app(ImageGenerator::class));
    }
}
