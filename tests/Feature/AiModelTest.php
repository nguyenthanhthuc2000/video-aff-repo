<?php

namespace Tests\Feature;

use App\Models\AiUsageLog;
use App\Models\User;
use App\Services\Claude\AiUsage;
use App\Services\ProductImport\PageSnapshot;
use App\Services\ProductImport\ProductExtractor;
use App\Support\ClaudeModels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AiModelTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/ai-model-test-'.uniqid();
        mkdir($this->dir);
        config([
            'videoaff.script_driver' => 'claude_cli',
            'videoaff.claude_cli.binary' => $this->dir.'/claude',
            'videoaff.claude_cli.oauth_token' => 'tok-123',
            'videoaff.claude_cli.model' => 'sonnet',
        ]);
        $line = json_encode(['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'structured_output' => ['name' => 'Áo', 'description' => 'D']]);
        file_put_contents($this->dir.'/claude', "#!/bin/sh\ncat > /dev/null\necho \"\$@\" > {$this->dir}/args.txt\ncat <<'JSON'\n{$line}\nJSON\n");
        chmod($this->dir.'/claude', 0755);
    }

    private function extract(): string
    {
        app(ProductExtractor::class)->extract(new PageSnapshot('https://shopee.vn/a', 'A', 'text'));

        return file_get_contents($this->dir.'/args.txt');
    }

    public function test_model_resolution_prefers_action_then_default_then_env()
    {
        $this->assertStringContainsString('--model sonnet', $this->extract());

        ClaudeModels::save('claude-opus-5-5', []);
        $this->assertStringContainsString('--model claude-opus-5-5', $this->extract());

        ClaudeModels::save('claude-opus-5-5', ['product_import' => 'claude-haiku-5-5']);
        $this->assertStringContainsString('--model claude-haiku-5-5', $this->extract());
        $this->assertSame('claude-opus-5-5', ClaudeModels::for('script', 'sonnet'));
        // Lượt gọi được ghi với model đã chọn (giá theo dòng haiku khi CLI không báo model).
        $this->assertSame('claude-haiku-5-5', AiUsageLog::latest('id')->first()->model);
    }

    public function test_admin_sees_estimates_per_model()
    {
        AiUsageLog::factory()->count(2)->create(['action' => 'script', 'input_tokens' => 10000, 'output_tokens' => 2000]);

        $this->actingAs(User::factory()->admin()->create())->get(route('admin.ai-models.show'))->assertInertia(fn (Assert $page) => $page
            ->component('admin/ai-models')
            ->has('models', 3)
            ->where('actions.0.action', 'script')
            ->where('actions.0.measured_calls', 2)
            ->where('actions.0.month_calls', 2)
            ->where('actions.0.estimates.claude-opus-5-5', fn ($cost) => abs($cost - AiUsage::cost('claude-opus-5-5', 10000, 2000)) < 0.000001)
            ->where('actions.0.estimates.claude-haiku-5-5', fn ($cost) => abs($cost - 0.02) < 0.000001)
            ->where('actions.1.measured_calls', 0));
    }

    public function test_admin_saves_models_and_invalid_model_is_rejected()
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put(route('admin.ai-models.update'), [
            'default' => 'claude-sonnet-5-5',
            'actions' => ['script' => 'claude-opus-5-5', 'product_import' => 'claude-haiku-5-5', 'scene_prompt_rewrite' => null],
        ])->assertSessionHasNoErrors();

        $this->assertSame('claude-sonnet-5-5', ClaudeModels::settings()['default']);
        $this->assertSame('claude-opus-5-5', ClaudeModels::settings()['actions']['script']);
        $this->assertNull(ClaudeModels::settings()['actions']['scene_prompt_rewrite']);

        $this->actingAs($admin)->put(route('admin.ai-models.update'), ['default' => 'gpt-9'])->assertSessionHasErrors('default');
        $this->actingAs(User::factory()->create())->get(route('admin.ai-models.show'))->assertForbidden();
    }
}
