<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Quản trị (/admin): cài đặt hệ thống, xác thực Claude / Muse, quản lý người dùng.
            $table->boolean('is_admin')->default(false)->after('email');
            // Tài khoản bị khoá không đăng nhập / dùng được ứng dụng.
            $table->timestamp('blocked_at')->nullable()->after('is_admin');
        });

        // Tài khoản admin cấu hình (ADMIN_EMAIL); chưa có thì người dùng đầu tiên (chủ hệ thống) là admin.
        DB::table('users')->where('email', config('app.admin.email'))->update(['is_admin' => true]);
        if (! DB::table('users')->where('is_admin', true)->exists()) {
            DB::table('users')->orderBy('id')->limit(1)->update(['is_admin' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['is_admin', 'blocked_at']));
    }
};
