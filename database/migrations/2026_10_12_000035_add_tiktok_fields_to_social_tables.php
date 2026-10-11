<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // TikTok: mã truy cập sống 24 giờ, làm mới bằng refresh token (365 ngày).
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->text('refresh_token')->nullable()->after('access_token');
            $table->timestamp('token_expires_at')->nullable()->after('refresh_token');
            $table->timestamp('refresh_expires_at')->nullable()->after('token_expires_at');
            $table->string('avatar_url', 1000)->nullable()->after('name');
        });
        // Tuỳ chọn khi đăng (TikTok: đăng thẳng / gửi hộp nháp, quyền xem, bình luận, duet, stitch, nội dung thương mại).
        Schema::table('social_posts', function (Blueprint $table) {
            $table->json('options')->nullable()->after('caption');
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', fn (Blueprint $table) => $table->dropColumn(['refresh_token', 'token_expires_at', 'refresh_expires_at', 'avatar_url']));
        Schema::table('social_posts', fn (Blueprint $table) => $table->dropColumn('options'));
    }
};
