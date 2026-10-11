<?php

namespace Tests\Feature;

use App\Jobs\GeneratePostCaptionsJob;
use App\Jobs\GenerateScriptJob;
use App\Models\AiUsageLog;
use App\Models\Scene;
use App\Models\SceneImage;
use App\Models\User;
use App\Models\VideoProject;
use App\Services\Claude\AiUsage;
use App\Services\ProductImport\PageSnapshot;
use App\Services\ProductImport\ProductExtractor;
use App\Services\ProductImport\ProductImportException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class AiCostTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->dir = sys_get_temp_dir().'/ai-cost-test-'.uniqid();
        mkdir($this->dir);
        config([
            'videoaff.script_driver' => 'claude_cli',
            'videoaff.claude_cli.binary' => $this->dir.'/claude',
            'videoaff.claude_cli.oauth_token' => 'tok-123',
            'videoaff.claude_cli.model' => null,
        ]);
    }

    private function fakeCli(array $result): void
    {
        $line = json_encode(['type' => 'result', 'subtype' => 'success', ...$result], JSON_UNESCAPED_UNICODE);
        file_put_contents($this->dir.'/claude', "#!/bin/sh\ncat > /dev/null\ncat <<'JSON'\n{$line}\nJSON\n");
        chmod($this->dir.'/claude', 0755);
    }

    public function test_cli_call_is_logged_with_tokens_cost_and_project()
    {
        $this->fakeCli([
            'is_error' => false,
            'duration_ms' => 8200,
            'total_cost_usd' => 0.0421,
            'modelUsage' => [
                'claude-haiku-5-5' => ['inputTokens' => 100, 'outputTokens' => 20, 'costUSD' => 0.0002],
                'claude-sonnet-5-5' => ['inputTokens' => 3000, 'outputTokens' => 1500, 'cacheReadInputTokens' => 400, 'costUSD' => 0.0419],
            ],
            'structured_output' => [
                'hooks' => ['H1', 'H2', 'H3'],
                'scenes' => [['visual' => 'V', 'on_screen_text' => 'T', 'voiceover' => 'L', 'image_prompt' => 'P', 'duration_ms' => 3000]],
                'caption' => 'C',
                'hashtags' => ['#a'],
            ],
        ]);
        $project = VideoProject::factory()->create();
        // Chỉ xét lượt viết kịch bản (đề xuất caption viết sau là lượt gọi riêng).
        Queue::fake([GeneratePostCaptionsJob::class]);

        GenerateScriptJob::dispatchSync($project);

        $log = AiUsageLog::sole();
        $this->assertSame('script', $log->action);
        $this->assertSame('claude_cli', $log->provider);
        $this->assertSame('claude-sonnet-5-5', $log->model);
        $this->assertTrue($log->success);
        $this->assertSame(3100, $log->input_tokens);
        $this->assertSame(1520, $log->output_tokens);
        $this->assertSame(400, $log->cache_read_tokens);
        $this->assertEqualsWithDelta(0.0421, (float) $log->cost_usd, 0.000001);
        $this->assertSame(8200, $log->duration_ms);
        $this->assertSame($project->id, $log->video_project_id);
        $this->assertSame($project->user_id, $log->user_id);
    }

    public function test_failed_cli_call_is_logged_as_error()
    {
        $this->fakeCli(['is_error' => true, 'result' => 'Something broke', 'total_cost_usd' => 0.003, 'usage' => ['input_tokens' => 500, 'output_tokens' => 10]]);

        try {
            app(ProductExtractor::class)->extract(new PageSnapshot('https://shopee.vn/a', 'A', 'text'));
            $this->fail('Phải ném lỗi.');
        } catch (ProductImportException) {
        }

        $log = AiUsageLog::sole();
        $this->assertSame('product_import', $log->action);
        $this->assertFalse($log->success);
        $this->assertSame(500, $log->input_tokens);
        $this->assertStringContainsString('Something broke', $log->error);
    }

    public function test_api_call_cost_uses_pricing_table()
    {
        $message = (object) ['stopReason' => 'end_turn', 'usage' => (object) [
            'inputTokens' => 1_000_000, 'outputTokens' => 100_000, 'cacheCreationInputTokens' => 0, 'cacheReadInputTokens' => 1_000_000,
        ]];

        $result = AiUsage::withContext(['subject' => 'scene:9'], fn () => AiUsage::api('scene_prompt_rewrite', 'claude-opus-5-5', fn () => $message));

        $this->assertSame($message, $result);
        $log = AiUsageLog::sole();
        // Opus mặc định: 5$ vào + 0.5$ đọc cache + 2.5$ ra.
        $this->assertEqualsWithDelta(8.0, (float) $log->cost_usd, 0.0001);
        $this->assertSame('scene:9', $log->subject);
        $this->assertSame('claude_api', $log->provider);
    }

    public function test_api_exception_is_logged_and_rethrown()
    {
        $this->expectException(RuntimeException::class);

        try {
            AiUsage::api('script', 'claude-sonnet-5-5', fn () => throw new RuntimeException('down'));
        } finally {
            $this->assertFalse(AiUsageLog::sole()->success);
        }
    }

    public function test_admin_sees_cost_breakdown()
    {
        $admin = User::factory()->admin()->create();
        $project = VideoProject::factory()->create();
        AiUsageLog::factory()->create(['action' => 'script', 'cost_usd' => 0.04, 'video_project_id' => $project->id, 'user_id' => $project->user_id]);
        AiUsageLog::factory()->create(['action' => 'script', 'cost_usd' => 0.02, 'video_project_id' => $project->id, 'user_id' => $project->user_id]);
        AiUsageLog::factory()->create(['action' => 'motion_analysis', 'cost_usd' => 0.1, 'success' => false]);
        AiUsageLog::factory()->create(['action' => 'script', 'cost_usd' => 5, 'created_at' => now()->subDays(60)]);

        $this->actingAs($admin)->get('/admin/ai-costs')->assertInertia(fn (Assert $page) => $page
            ->component('admin/ai-costs')
            ->where('totals.calls', 3)
            ->where('totals.failed', 1)
            ->where('totals.cost_usd', fn ($cost) => abs($cost - 0.16) < 0.0001)
            ->where('totals.projects', 1)
            ->where('totals.cost_per_project', fn ($cost) => abs($cost - 0.06) < 0.0001)
            ->where('byAction.0.action', 'motion_analysis')
            ->where('byAction.1.calls', 2)
            ->where('topProjects.0.id', $project->id)
            ->has('byDay', 30)
            ->has('logs.data', 3));

        $this->actingAs($admin)->get('/admin/ai-costs?period=all&action=script')
            ->assertInertia(fn (Assert $page) => $page->where('totals.calls', 3)->where('filters.action', 'script'));
    }

    public function test_non_admin_cannot_open_costs()
    {
        $this->actingAs(User::factory()->create())->get('/admin/ai-costs')->assertForbidden();
    }

    public function test_admin_updates_pricing()
    {
        $admin = User::factory()->admin()->create();
        $prices = ['opus' => ['input' => 6, 'output' => 30], 'sonnet' => ['input' => 3, 'output' => 15], 'haiku' => ['input' => 1, 'output' => 5]];

        $this->actingAs($admin)->put('/admin/ai-costs/pricing', ['usd_vnd' => 25500, 'prices' => $prices])->assertSessionHasNoErrors();

        $this->assertSame(25500, AiUsage::settings()['usd_vnd']);
        $this->assertEqualsWithDelta(36.0, AiUsage::cost('claude-opus-5-5', 1_000_000, 1_000_000), 0.0001);

        $this->actingAs($admin)->put('/admin/ai-costs/pricing', ['usd_vnd' => 5, 'prices' => $prices])->assertSessionHasErrors('usd_vnd');
    }

    public function test_admin_projects_show_cost_per_video_and_step()
    {
        $admin = User::factory()->admin()->create();
        $project = VideoProject::factory()->create();
        $sibling = VideoProject::factory()->for($project->product)->create(['created_at' => now()->subDay()]);
        AiUsageLog::factory()->create(['action' => 'script', 'cost_usd' => 0.03, 'video_project_id' => $project->id]);
        AiUsageLog::factory()->create(['action' => 'scene_prompt_rewrite', 'cost_usd' => 0.005, 'video_project_id' => $project->id]);
        AiUsageLog::factory()->create(['action' => 'scene_prompt_rewrite', 'cost_usd' => 0.005, 'video_project_id' => $project->id, 'success' => false]);
        // Đọc trang sản phẩm dùng chung cho 2 video cùng sản phẩm: mỗi video 0.01.
        AiUsageLog::factory()->create(['action' => 'product_import', 'cost_usd' => 0.02, 'subject' => "product:{$project->product_id}"]);
        $scene = Scene::factory()->for($project)->create();
        SceneImage::factory()->for($scene)->count(2)->create(['cost_usd' => 0]);

        $this->actingAs($admin)->get('/admin/projects?sort=cost')->assertInertia(fn (Assert $page) => $page
            ->component('admin/projects')
            ->where('projects.data.0.id', $project->id)
            ->where('projects.data.0.cost.total_usd', fn ($cost) => abs($cost - 0.05) < 0.00001)
            ->where('projects.data.0.cost.calls', 4)
            ->where('projects.data.0.cost.steps.0.key', 'product_import')
            ->where('projects.data.0.cost.steps.0.shared_with', 2)
            ->where('projects.data.0.cost.steps.1.key', 'script')
            ->where('projects.data.0.cost.steps.2.key', 'scene_prompt_rewrite')
            ->where('projects.data.0.cost.steps.2.calls', 2)
            ->where('projects.data.0.cost.steps.2.failed', 1)
            ->where('projects.data.0.cost.steps.3.key', 'images')
            ->where('projects.data.0.cost.steps.3.calls', 2)
            ->where('projects.data.1.id', $sibling->id)
            ->where('projects.data.1.cost.total_usd', fn ($cost) => abs($cost - 0.01) < 0.00001));
    }

    public function test_owner_does_not_see_costs_on_project_page()
    {
        $project = VideoProject::factory()->create();

        $this->actingAs($project->user)->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page->missing('project.image_cost_usd')->missing('project.cost'));
    }

    public function test_project_ai_calls_include_shared_steps_for_admin_only()
    {
        $admin = User::factory()->admin()->create();
        $project = VideoProject::factory()->create();
        AiUsageLog::factory()->create(['action' => 'script', 'video_project_id' => $project->id]);
        AiUsageLog::factory()->create(['action' => 'product_import', 'subject' => "product:{$project->product_id}"]);
        AiUsageLog::factory()->create(['action' => 'script', 'video_project_id' => VideoProject::factory()->create()->id]);

        $this->actingAs($admin)->getJson(route('admin.projects.ai-calls', $project))
            ->assertOk()
            ->assertJsonCount(2, 'calls')
            ->assertJsonPath('calls.0.action', 'product_import')
            ->assertJsonPath('calls.0.shared', true)
            ->assertJsonPath('calls.1.shared', false);

        $this->actingAs($project->user)->getJson(route('admin.projects.ai-calls', $project))->assertForbidden();
    }

    public function test_old_cli_calls_are_imported_from_log_once()
    {
        $project = VideoProject::factory()->create();
        $scene = Scene::factory()->for($project)->create();
        $log = $this->dir.'/laravel.log';
        file_put_contents($log, implode("\n", [
            '[2026-10-09 15:31:44] testing.INFO: claude_cli {"context":"script:'.$project->id.'","subtype":"success","duration_ms":16004,"notional_cost_usd":0.042}',
            '[2026-10-09 15:40:00] testing.INFO: claude_cli {"context":"scene_prompt_rewrite","subtype":"success","duration_ms":2900,"notional_cost_usd":0.0058}',
            '[2026-10-09 15:40:00] testing.INFO: scene_prompt_rewrite {"scene_id":'.$scene->id.',"old":"a","new":"b"}',
            '[2026-10-09 15:41:00] local.INFO: claude_cli {"context":"script:1","subtype":"success","duration_ms":1,"notional_cost_usd":9}',
            '[2026-10-09 15:42:00] testing.INFO: claude_cli {"context":"product_import","subtype":"error_max_turns","notional_cost_usd":0.01}',
        ])."\n");

        $this->artisan('ai-usage:import-log', ['path' => $log])->assertSuccessful();
        $this->artisan('ai-usage:import-log', ['path' => $log])->expectsOutputToContain('bỏ qua 3')->assertSuccessful();

        $this->assertSame(3, AiUsageLog::count());
        $script = AiUsageLog::where('action', 'script')->sole();
        $this->assertSame($project->id, $script->video_project_id);
        $this->assertSame($project->user_id, $script->user_id);
        $this->assertSame(16004, $script->duration_ms);
        $rewrite = AiUsageLog::where('action', 'scene_prompt_rewrite')->sole();
        $this->assertSame($project->id, $rewrite->video_project_id);
        $this->assertSame("scene:{$scene->id}", $rewrite->subject);
        $this->assertFalse(AiUsageLog::where('action', 'product_import')->sole()->success);
    }
}
