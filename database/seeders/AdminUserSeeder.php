<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    /**
     * Tạo (hoặc cập nhật) tài khoản admin theo config('app.admin').
     *
     * - Có ADMIN_PASSWORD: luôn đặt mật khẩu theo giá trị đó.
     * - Không có và tài khoản chưa tồn tại: sinh mật khẩu ngẫu nhiên, in ra một lần.
     * - Không có và tài khoản đã tồn tại: giữ nguyên mật khẩu cũ.
     */
    public function run(): void
    {
        $config = config('app.admin');

        $user = User::firstOrNew(['email' => $config['email']]);
        $user->name = $config['name'];
        $user->email_verified_at ??= now();
        $user->is_admin = true;

        $generated = null;

        if (filled($config['password'])) {
            $user->password = $config['password'];
        } elseif (! $user->exists) {
            $generated = Str::password(16, symbols: false);
            $user->password = $generated;
        }

        $user->save();

        if ($generated !== null) {
            $this->command?->warn("Admin {$user->email} được tạo với mật khẩu: {$generated}");
            $this->command?->warn('Hãy đổi mật khẩu sau khi đăng nhập, hoặc đặt ADMIN_PASSWORD trong .env rồi chạy lại seeder.');
        } else {
            $this->command?->info("Admin {$user->email} đã sẵn sàng.");
        }
    }
}
