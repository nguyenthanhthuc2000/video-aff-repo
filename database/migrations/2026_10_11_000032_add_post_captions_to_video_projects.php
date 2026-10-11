<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Đề xuất caption đăng video: bản bán hàng và bản câu view (Claude viết sau khi có kịch bản).
        Schema::table('video_projects', function (Blueprint $table) {
            $table->string('post_captions_status', 20)->nullable()->after('muse_plan_request_id');
            $table->json('post_captions')->nullable()->after('post_captions_status');
        });
    }

    public function down(): void
    {
        Schema::table('video_projects', fn (Blueprint $table) => $table->dropColumn(['post_captions_status', 'post_captions']));
    }
};
