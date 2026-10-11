<?php

namespace Tests\Feature;

use App\Enums\VideoProjectStatus;
use App\Jobs\GenerateScriptJob;
use App\Models\Scene;
use App\Models\User;
use App\Models\VideoProject;
use App\Services\Script\ClaudeScriptWriter;
use App\Services\Script\FakeScriptWriter;
use App\Services\Script\ScriptSchema;
use App\Services\Script\ScriptWriter;
use App\Services\Script\ScriptWriterException;
use App\Support\VideoPreset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScriptTest extends TestCase
{
    use RefreshDatabase;

    public function test_fake_writer_is_used_without_api_key()
    {
        config(['videoaff.script_driver' => 'fake']);

        $this->assertInstanceOf(FakeScriptWriter::class, app(ScriptWriter::class));
    }

    public function test_claude_writer_is_used_with_claude_driver()
    {
        config(['videoaff.script_driver' => 'claude', 'videoaff.claude.api_key' => 'test-key']);

        $this->assertInstanceOf(ClaudeScriptWriter::class, app(ScriptWriter::class));
    }

    public function test_generate_queues_job_and_marks_project_scripting()
    {
        Queue::fake();
        $project = VideoProject::factory()->create();

        $this->actingAs($project->user)
            ->post(route('projects.script.store', $project))
            ->assertRedirect();

        $this->assertSame(VideoProjectStatus::Scripting, $project->fresh()->status);
        Queue::assertPushed(GenerateScriptJob::class, fn ($job) => $job->project->is($project));
    }

    public function test_generate_writes_script_and_scenes()
    {
        VideoPreset::saveSettings(['mode' => 'fixed', 'scene_count' => 4, 'target_seconds' => 16]);
        $project = VideoProject::factory()->create();

        $this->actingAs($project->user)->post(route('projects.script.store', $project));

        $project->refresh();
        $this->assertSame(VideoProjectStatus::ScriptReady, $project->status);
        $this->assertCount(5, $project->script['hooks']);
        $this->assertCount(5, $project->script['hook_themes']);
        $this->assertSame($project->script['recommended_hook'], $project->selected_hook);
        $this->assertSame([1, 2, 3, 4], $project->scenes->pluck('position')->all());
        $this->assertSame(16000, $project->scenes->sum('duration_ms'));
    }

    public function test_regenerate_replaces_old_scenes()
    {
        VideoPreset::saveSettings(['mode' => 'fixed', 'scene_count' => 4, 'target_seconds' => 16]);
        $project = VideoProject::factory()->create(['status' => VideoProjectStatus::ScriptReady, 'script' => ['hooks' => ['a']]]);
        $old = Scene::factory()->for($project)->create();

        $this->actingAs($project->user)->post(route('projects.script.store', $project));

        $this->assertModelMissing($old);
        $this->assertCount(4, $project->scenes()->get());
    }

    public function test_cannot_generate_while_busy_or_after_imaging()
    {
        Queue::fake();
        $project = VideoProject::factory()->create(['status' => VideoProjectStatus::Imaging]);

        $this->actingAs($project->user)->post(route('projects.script.store', $project));

        $this->assertSame(VideoProjectStatus::Imaging, $project->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_other_users_cannot_generate_or_edit()
    {
        $project = VideoProject::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($other)->post(route('projects.script.store', $project))->assertForbidden();
        $this->actingAs($other)->put(route('projects.script.update', $project), [])->assertForbidden();
    }

    public function test_writer_error_marks_project_failed()
    {
        $this->app->bind(ScriptWriter::class, fn () => new class implements ScriptWriter
        {
            public function write(VideoProject $project): array
            {
                throw new ScriptWriterException('Claude từ chối viết kịch bản cho sản phẩm này.');
            }
        });
        $project = VideoProject::factory()->create();

        $this->actingAs($project->user)->post(route('projects.script.store', $project));

        $project->refresh();
        $this->assertSame(VideoProjectStatus::Failed, $project->status);
        $this->assertSame('Claude từ chối viết kịch bản cho sản phẩm này.', $project->error);
    }

    public function test_update_saves_hooks_and_scenes()
    {
        $project = VideoProject::factory()->create();
        $this->actingAs($project->user)->post(route('projects.script.store', $project));
        $scene = $project->scenes()->first();

        $this->put(route('projects.script.update', $project), [
            'hooks' => ['Hook A', 'Hook B'],
            'selected_hook' => 1,
            'caption' => 'Caption mới',
            'hashtags' => ['#vay'],
            'scenes' => [[
                'id' => $scene->id,
                'visual' => 'Cảnh mới',
                'on_screen_text' => 'Chữ',
                'voiceover' => 'Lời',
                'image_prompt' => 'new prompt',
                'duration_ms' => 4500,
            ]],
        ])->assertSessionHasNoErrors();

        $project->refresh();
        $this->assertSame(['Hook A', 'Hook B'], $project->script['hooks']);
        $this->assertSame(1, $project->selected_hook);
        $this->assertSame(['#vay'], $project->script['hashtags']);
        $this->assertSame('Cảnh mới', $scene->fresh()->visual);
        $this->assertSame(4500, $scene->fresh()->duration_ms);
    }

    public function test_update_rejects_scenes_from_other_projects_and_bad_hook_index()
    {
        $project = VideoProject::factory()->create();
        $this->actingAs($project->user)->post(route('projects.script.store', $project));
        $foreign = Scene::factory()->create();

        $this->put(route('projects.script.update', $project), [
            'hooks' => ['Hook A'],
            'selected_hook' => 2,
            'scenes' => [[
                'id' => $foreign->id,
                'visual' => 'x',
                'image_prompt' => 'x',
                'duration_ms' => 3000,
            ]],
        ])->assertSessionHasErrors(['selected_hook', 'scenes.0.id']);
    }

    public function test_schema_normalize_clamps_and_validates()
    {
        $script = ScriptSchema::normalize([
            'hooks' => ['a', 'b', 'c', 'd', 'e', 'f'],
            'hook_themes' => ['Tò mò'],
            'recommended_hook' => 9,
            'scenes' => [['visual' => 'v', 'image_prompt' => 'p', 'duration_ms' => 50000]],
        ]);

        $this->assertCount(5, $script['hooks']);
        $this->assertSame(['Tò mò', '', '', '', ''], $script['hook_themes']);
        $this->assertSame(0, $script['recommended_hook']);
        $this->assertSame(30000, $script['scenes'][0]['duration_ms']);
        $this->assertSame([], $script['hashtags']);

        $this->expectException(ScriptWriterException::class);
        ScriptSchema::normalize(['hooks' => ['a'], 'scenes' => []]);
    }
}
