<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_projects', function (Blueprint $table) {
            // Chọn nhiều kiểu chuyển động; các cảnh dùng lần lượt. motion_style giữ kiểu đầu tiên.
            $table->json('motion_styles')->nullable()->after('motion_style');
        });

        // Dự án có sẵn: chuyển kiểu đang chọn sang danh sách.
        DB::table('video_projects')->whereNotNull('motion_style')->orderBy('id')->each(
            fn ($project) => DB::table('video_projects')->where('id', $project->id)->update(['motion_styles' => json_encode([$project->motion_style])]),
        );
    }

    public function down(): void
    {
        Schema::table('video_projects', fn (Blueprint $table) => $table->dropColumn('motion_styles'));
    }
};
