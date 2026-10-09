<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_admin_with_configured_password()
    {
        config(['app.admin' => ['name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'secret-pass-123']]);

        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', 'admin@test.local')->sole();
        $this->assertSame('Admin', $admin->name);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertTrue(Hash::check('secret-pass-123', $admin->password));
    }

    public function test_it_generates_password_when_none_configured()
    {
        config(['app.admin' => ['name' => 'Admin', 'email' => 'admin@test.local', 'password' => null]]);

        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', 'admin@test.local')->sole();
        $this->assertNotEmpty($admin->password);
    }

    public function test_rerun_without_password_keeps_existing_password()
    {
        User::factory()->create(['email' => 'admin@test.local', 'password' => 'old-pass-123']);
        config(['app.admin' => ['name' => 'Admin', 'email' => 'admin@test.local', 'password' => null]]);

        $this->seed(AdminUserSeeder::class);

        $this->assertSame(1, User::where('email', 'admin@test.local')->count());
        $this->assertTrue(Hash::check('old-pass-123', User::where('email', 'admin@test.local')->sole()->password));
    }

    public function test_database_seeder_creates_admin()
    {
        config(['app.admin' => ['name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'secret-pass-123']]);

        $this->seed();

        $this->assertDatabaseHas('users', ['email' => 'admin@test.local']);
    }
}
