<?php

namespace Tests\Feature;

use App\Enums\SceneImageStatus;
use App\Enums\VideoProjectStatus;
use App\Jobs\GenerateSceneImageJob;
use App\Jobs\GenerateScriptJob;
use App\Models\AiUsageLog;
use App\Models\Scene;
use App\Models\User;
use App\Models\VideoProject;
use App\Services\Claude\AiUsage;
use App\Support\CreditPricing;
use App\Support\Credits;
use App\Support\Media;
use App\Support\ProjectCredits;
use App\Support\ProjectProfit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function projectWithScenes(User $user, int $scenes = 2): VideoProject
    {
        $project = VideoProject::factory()->for($user)->create(['status' => VideoProjectStatus::ScriptReady, 'script' => ['hooks' => ['h']]]);
        Media::disk()->put($project->fashionModel->photo_path, 'model-photo');
        $project->product->images()->create(['path' => 'products/a.jpg', 'is_primary' => true]);
        Media::disk()->put('products/a.jpg', 'product-photo');
        Scene::factory()->count($scenes)->for($project)->sequence(fn ($s) => ['position' => $s->index + 1])->create();

        return $project;
    }

    public function test_generated_images_are_charged_once_and_shown_as_project_cost()
    {
        $user = User::factory()->create(['credit_balance' => 5000]);
        $project = $this->projectWithScenes($user);

        $this->actingAs($user)->post(route('projects.images.store', $project))->assertRedirect();

        // 2 ảnh × 800.
        $this->assertSame(5000 - 1600, $user->fresh()->credit_balance);
        $this->assertSame(2, $user->creditTransactions()->where('type', 'usage')->where('action', 'image')->count());
        $credits = ProjectCredits::forProjects(collect([$project]))[$project->id];
        $this->assertFalse($credits['estimated']);
        $this->assertSame(1600, $credits['total']);
        $this->assertSame([['key' => 'images', 'label' => 'Ảnh cảnh', 'count' => 2, 'credits' => 1600]], $credits['steps']);

        // Cùng reference không trừ lần hai.
        $image = $project->scenes[0]->latestImage;
        Credits::charge($user->id, 800, 'image', "image:{$image->id}", 'lặp');
        $this->assertSame(3400, $user->fresh()->credit_balance);
    }

    public function test_actions_are_blocked_when_balance_is_too_low_even_for_admins()
    {
        Queue::fake();
        $admin = User::factory()->admin()->create(['credit_balance' => 1000]);
        $project = $this->projectWithScenes($admin, 3);

        // 3 ảnh cần 2.400 Credits.
        $this->actingAs($admin)->post(route('projects.images.store', $project))
            ->assertSessionHas('status', fn ($status) => str_contains($status, 'Không đủ Credits'));
        $this->assertSame(0, $project->sceneImages()->count());
        Queue::assertNothingPushed();

        $admin->forceFill(['credit_balance' => 0])->save();
        $this->actingAs($admin)->post(route('projects.script.store', $project->fresh()))
            ->assertSessionHas('status', fn ($status) => str_contains($status, 'Không đủ Credits'));
        Queue::assertNothingPushed();
    }

    public function test_jobs_stop_without_charging_when_balance_runs_out()
    {
        $user = User::factory()->broke()->create();
        $project = $this->projectWithScenes($user, 1);
        $image = $project->scenes[0]->images()->create();

        dispatch_sync(new GenerateSceneImageJob($image));
        $this->assertSame(SceneImageStatus::Failed, $image->fresh()->status);
        $this->assertStringContainsString('Không đủ Credits', $image->fresh()->error);

        $project->update(['status' => VideoProjectStatus::Scripting]);
        GenerateScriptJob::dispatchSync($project);
        $this->assertSame(VideoProjectStatus::Failed, $project->fresh()->status);
        $this->assertStringContainsString('Không đủ Credits', $project->fresh()->error);
        $this->assertSame(0, $user->creditTransactions()->count());
    }

    public function test_ai_calls_are_charged_by_real_cost_and_failed_calls_are_free()
    {
        $user = User::factory()->create(['credit_balance' => 10000]);
        $project = VideoProject::factory()->for($user)->create();
        $message = (object) ['stopReason' => 'end_turn', 'usage' => (object) ['inputTokens' => 10000, 'outputTokens' => 2000]];

        AiUsage::withContext(['user_id' => $user->id, 'video_project_id' => $project->id], fn () => AiUsage::api('script', 'claude-sonnet-5-5', fn () => $message));
        try {
            AiUsage::withContext(['user_id' => $user->id], fn () => AiUsage::api('script', 'claude-sonnet-5-5', fn () => throw new \RuntimeException('down')));
        } catch (\RuntimeException) {
        }

        $log = AiUsageLog::where('success', true)->sole();
        $expected = CreditPricing::forAiCall((float) $log->cost_usd, $log->model);
        $this->assertSame(10000 - $expected, $user->fresh()->credit_balance);
        $charge = $user->creditTransactions()->sole();
        $this->assertSame("ai:{$log->id}", $charge->reference);
        $this->assertSame($project->id, $charge->video_project_id);
        $this->assertSame('script', $charge->action);
    }

    public function test_new_users_get_signup_bonus()
    {
        $this->post(route('register'), ['name' => 'An', 'email' => 'an@example.com', 'password' => 'password', 'password_confirmation' => 'password']);

        $user = User::where('email', 'an@example.com')->sole();
        $this->assertSame(CreditPricing::DEFAULT_SIGNUP_BONUS, $user->credit_balance);
        $this->assertSame('bonus', $user->creditTransactions()->sole()->type);
    }

    public function test_projects_without_charges_show_estimate()
    {
        $project = VideoProject::factory()->create();
        AiUsageLog::factory()->create(['video_project_id' => $project->id, 'action' => 'script', 'model' => 'claude-sonnet-5-5', 'cost_usd' => 0.05]);

        $credits = ProjectCredits::forProjects(collect([$project]))[$project->id];
        $this->assertTrue($credits['estimated']);
        $this->assertSame(3900, $credits['total']);
    }

    public function test_admin_project_page_shows_cost_and_sale_per_step()
    {
        $user = User::factory()->create(['credit_balance' => 100000]);
        $project = VideoProject::factory()->for($user)->create();
        $other = VideoProject::factory()->for($user)->for($project->product)->create();
        // Đọc sản phẩm: vốn $0.01 (260đ), trừ 1.040 Credits theo sản phẩm, chia 2 video.
        $import = AiUsageLog::factory()->create(['user_id' => $user->id, 'subject' => "product:{$project->product_id}", 'action' => 'product_import', 'model' => 'claude-haiku-5-5', 'cost_usd' => 0.01]);
        Credits::charge($user->id, 1040, 'product_import', "ai:{$import->id}", 'Sản phẩm');
        $script = AiUsageLog::factory()->create(['user_id' => $user->id, 'video_project_id' => $project->id, 'action' => 'script', 'model' => 'claude-sonnet-5-5', 'cost_usd' => 0.05]);
        Credits::charge($user->id, 3900, 'script', "ai:{$script->id}", 'Viết kịch bản', $project->id);
        Credits::charge($user->id, 800, 'image', 'image:1', 'Ảnh cảnh 1', $project->id);

        $profit = ProjectProfit::for($project);

        $rows = collect($profit['rows'])->keyBy('key');
        $this->assertFalse($profit['estimated']);
        $this->assertSame(130, $rows['product_import']['cost_vnd']);
        $this->assertSame(520, $rows['product_import']['sale_credits']);
        $this->assertSame(2, $rows['product_import']['shared_with']);
        $this->assertSame(1300, $rows['script']['cost_vnd']);
        $this->assertSame(3900, $rows['script']['sale_vnd']);
        $this->assertSame(2600, $rows['script']['profit_vnd']);
        $this->assertSame(800, $rows['images']['profit_vnd']);
        $this->assertSame(130 + 1300, $profit['totals']['cost_vnd']);
        $this->assertSame(520 + 3900 + 800, $profit['totals']['sale_credits']);
        $this->assertNotNull($other);

        $this->actingAs(User::factory()->admin()->create())->get(route('admin.projects.show', $project))
            ->assertInertia(fn ($page) => $page->where('profit.totals.sale_vnd', 5220)->where('profit.totals.profit_vnd', 5220 - 1430));
    }
}
