<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VideoProject;
use App\Support\Credits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CreditHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_sees_own_history_with_summary_and_filters()
    {
        $user = User::factory()->broke()->create();
        $project = VideoProject::factory()->for($user)->create();
        Credits::record($user, ['type' => 'bonus', 'credits' => 30000, 'description' => 'Tặng khi đăng ký']);
        Credits::record($user, ['type' => 'topup', 'credits' => 100000, 'amount_vnd' => 100000, 'method' => 'momo', 'description' => 'MOMO-ghi-chu-noi-bo']);
        Credits::charge($user->id, 800, 'image', 'image:1', 'Ảnh cảnh 2', $project->id);
        Credits::record($user, ['type' => 'adjustment', 'credits' => 5000, 'description' => 'bù lỗi nội bộ']);
        Credits::record(User::factory()->create(), ['type' => 'topup', 'credits' => 999, 'amount_vnd' => 999]);

        $this->actingAs($user)->get(route('credits.index'))->assertInertia(fn (Assert $page) => $page
            ->component('credits/index')
            ->where('balance', 134200)
            ->where('summary.topup', 100000)
            ->where('summary.bonus', 35000)
            ->where('summary.used', 800)
            ->has('transactions.data', 4)
            ->where('transactions.data.0.title', 'Quản trị viên cộng Credits')
            ->where('transactions.data.0.detail', null)
            ->where('transactions.data.1.title', 'Tạo ảnh cảnh')
            ->where('transactions.data.1.detail', 'Ảnh cảnh 2')
            ->where('transactions.data.1.credits', -800)
            ->where('transactions.data.1.project.id', $project->id)
            ->where('transactions.data.2.detail', 'Ví MoMo')
            ->where('transactions.data.3.title', 'Tặng khi đăng ký'));

        // Ghi chú nội bộ của admin không lộ ra.
        $this->actingAs($user)->get(route('credits.index'))->assertDontSee('ghi-chu-noi-bo')->assertDontSee('bù lỗi nội bộ');

        $this->actingAs($user)->get(route('credits.index', ['type' => 'usage']))->assertInertia(fn (Assert $page) => $page
            ->has('transactions.data', 1)->where('filters.type', 'usage'));
    }

    public function test_history_requires_login()
    {
        $this->get(route('credits.index'))->assertRedirect(route('login'));
    }
}
