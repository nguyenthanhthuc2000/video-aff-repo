<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admins_can_open_admin_pages()
    {
        $user = User::factory()->create();
        foreach (['/admin', '/admin/users', '/admin/projects', '/admin/jobs', '/admin/product-categories', '/admin/settings/video', '/admin/settings/claude'] as $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get('/admin')->assertInertia(fn (Assert $page) => $page->component('admin/dashboard')->has('stats'));
        $this->actingAs($admin)->get('/admin/users')->assertInertia(fn (Assert $page) => $page->component('admin/users')->has('users.data', 2));
        $this->actingAs($admin)->get('/admin/projects')->assertOk();
        $this->actingAs($admin)->get('/admin/jobs')->assertOk();
        $this->actingAs($admin)->get('/admin/product-categories')->assertInertia(fn (Assert $page) => $page->has('categories', 14));
    }

    public function test_admin_can_block_and_promote_but_not_self()
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)->put(route('admin.users.update', $user), ['blocked' => true, 'is_admin' => true]);
        $user->refresh();
        $this->assertTrue($user->isBlocked());
        $this->assertTrue($user->isAdmin());

        $this->actingAs($admin)->put(route('admin.users.update', $admin), ['is_admin' => false]);
        $this->assertTrue($admin->fresh()->isAdmin());

        // Bị khoá: bị đăng xuất khi vào ứng dụng.
        $this->actingAs($user)->get('/products')->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
