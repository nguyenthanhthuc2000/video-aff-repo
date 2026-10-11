<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tài khoản mạng xã hội đã kết nối (hiện có: Trang Facebook), mã truy cập lưu mã hoá.
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('external_id', 64);
            $table->string('name');
            $table->text('access_token');
            $table->timestamps();
            $table->unique(['user_id', 'provider', 'external_id']);
        });

        // Mỗi lần đăng video lên một tài khoản: trạng thái, link bài, lỗi.
        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('render_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('social_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 20);
            $table->string('account_name')->nullable();
            $table->text('caption');
            $table->string('status', 20)->default('pending');
            $table->string('external_id', 64)->nullable();
            $table->string('permalink', 500)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
        Schema::dropIfExists('social_accounts');
    }
};
