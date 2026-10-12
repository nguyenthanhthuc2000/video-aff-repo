<?php

namespace Tests\Feature;

use App\Models\AiUsageLog;
use App\Models\Product;
use App\Models\User;
use App\Models\VideoProject;
use App\Support\Credits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminUserDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_user_stats_and_tabs()
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->broke()->create();
        $product = Product::factory()->for($user)->create();
        $project = VideoProject::factory()->for($user)->for($product)->create();
        AiUsageLog::factory()->create(['user_id' => $user->id, 'video_project_id' => $project->id, 'cost_usd' => 0.05]);
        Credits::record($user, ['type' => 'topup', 'credits' => 2100, 'amount_vnd' => 200000, 'package' => 'popular', 'method' => 'bank']);
        Credits::record($user, ['type' => 'usage', 'credits' => -58, 'action' => 'video', 'video_project_id' => $project->id]);
        Credits::record($user, ['type' => 'refund', 'credits' => 8, 'video_project_id' => $project->id]);

        $this->actingAs($admin)->get(route('admin.users.show', $user))->assertInertia(fn (Assert $page) => $page
            ->component('admin/user-show')
            ->where('user.credit_balance', 2050)
            ->where('stats.topup_vnd', 200000)
            ->where('stats.topup_credits', 2100)
            ->where('stats.used_credits', 58)
            ->where('stats.refunded_credits', 8)
            ->where('stats.products', 1)
            ->where('stats.projects', 1)
            ->where('stats.ai_cost_usd', fn ($cost) => abs($cost - 0.05) < 0.0001)
            ->where('tab', 'topups')
            ->has('list.data', 1)
            ->where('list.data.0.amount_vnd', 200000));

        $this->actingAs($admin)->get(route('admin.users.show', [$user, 'tab' => 'usage']))->assertInertia(fn (Assert $page) => $page
            ->has('list.data', 2)
            ->where('list.data.0.type', 'refund')
            ->where('list.data.1.credits', -58)
            ->where('list.data.1.balance_after', 2042)
            ->where('list.data.1.project.id', $project->id));

        $this->actingAs($admin)->get(route('admin.users.show', [$user, 'tab' => 'videos']))->assertInertia(fn (Assert $page) => $page
            ->has('list.data', 1)->where('list.data.0.id', $project->id)->where('list.data.0.ai_cost_usd', fn ($cost) => abs($cost - 0.05) < 0.0001));

        $this->actingAs($admin)->get(route('admin.users.show', [$user, 'tab' => 'products']))->assertInertia(fn (Assert $page) => $page
            ->has('list.data', 1)->where('list.data.0.name', $product->name)->where('list.data.0.video_projects_count', 1));
    }

    public function test_non_admin_cannot_view_user_detail()
    {
        $this->actingAs(User::factory()->create())->get(route('admin.users.show', User::factory()->create()))->assertForbidden();
    }

    public function test_admin_tops_up_and_adjusts_credits()
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->broke()->create();

        $this->actingAs($admin)->post(route('admin.users.credits.store', $user), [
            'type' => 'topup', 'credits' => 1020, 'amount_vnd' => 100000, 'method' => 'momo', 'description' => 'MOMO123',
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.users.credits.store', $user), [
            'type' => 'adjustment', 'credits' => -20, 'description' => 'Trừ nhầm lần trước',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1000, $user->fresh()->credit_balance);
        $topup = $user->creditTransactions()->where('type', 'topup')->sole();
        $this->assertSame($admin->id, $topup->created_by);
        $this->assertSame(1020, $topup->balance_after);
        $this->assertSame('momo', $topup->method);

        // Không trừ quá số dư, điều chỉnh phải có lý do, nạp phải có số tiền.
        $this->actingAs($admin)->post(route('admin.users.credits.store', $user), ['type' => 'adjustment', 'credits' => -5000, 'description' => 'x'])
            ->assertSessionHasErrors('credits');
        $this->actingAs($admin)->post(route('admin.users.credits.store', $user), ['type' => 'adjustment', 'credits' => 5])
            ->assertSessionHasErrors('description');
        $this->actingAs($admin)->post(route('admin.users.credits.store', $user), ['type' => 'topup', 'credits' => 5])
            ->assertSessionHasErrors('amount_vnd');
        $this->assertSame(1000, $user->fresh()->credit_balance);
    }

    public function test_admin_adds_credits_to_own_account()
    {
        $admin = User::factory()->admin()->broke()->create();

        $this->actingAs($admin)->post(route('admin.users.credits.store', $admin), [
            'type' => 'adjustment', 'credits' => 50000, 'description' => 'Admin tự cộng để dùng thử',
        ])->assertSessionHasNoErrors();

        $this->assertSame(50000, $admin->fresh()->credit_balance);
        $this->assertSame($admin->id, $admin->creditTransactions()->sole()->created_by);
    }

    public function test_user_sees_own_balance_and_history_on_billing()
    {
        $user = User::factory()->broke()->create();
        Credits::record($user, ['type' => 'topup', 'credits' => 500, 'amount_vnd' => 50000, 'description' => 'ghi chú nội bộ']);
        Credits::record(User::factory()->broke()->create(), ['type' => 'topup', 'credits' => 999, 'amount_vnd' => 1]);

        $this->actingAs($user)->get(route('billing.index'))->assertInertia(fn (Assert $page) => $page
            ->where('balance', 500)
            ->has('transactions', 1)
            ->where('transactions.0.credits', 500)
            ->missing('transactions.0.description'));
    }
}
