<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Muse xem video mẫu + nghe nhạc rồi lên phân cảnh (đoạn nhạc, từng shot, lúc nào nhép lời) trước khi viết kịch bản.
        Schema::table('video_projects', function (Blueprint $table) {
            $table->string('muse_plan_status', 20)->nullable()->after('music_start_ms');
            $table->json('muse_plan')->nullable()->after('muse_plan_status');
            $table->string('muse_plan_error', 500)->nullable()->after('muse_plan');
            $table->string('muse_plan_request_id', 20)->nullable()->after('muse_plan_error');
        });
        // Shot dài (10–30 giây): Muse làm từng đoạn nối tiếp từ khung cuối đoạn trước, xong hết thì ghép thành clip của cảnh.
        Schema::table('scenes', function (Blueprint $table) {
            $table->json('clip_parts')->nullable()->after('clip_path');
        });
    }

    public function down(): void
    {
        Schema::table('video_projects', fn (Blueprint $table) => $table->dropColumn(['muse_plan_status', 'muse_plan', 'muse_plan_error', 'muse_plan_request_id']));
        Schema::table('scenes', fn (Blueprint $table) => $table->dropColumn('clip_parts'));
    }
};
