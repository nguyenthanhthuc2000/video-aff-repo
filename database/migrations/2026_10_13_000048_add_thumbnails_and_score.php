<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ảnh bìa YouTube cho mỗi bản xuất (tập phim, bài tiếng Anh) và nhạc riêng Claude sáng tác cho từng tập
 * (score: các đoạn nhạc theo phân đoạn; score_status / score_error như danh sách âm thanh hiệu ứng).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('episode_renders', function (Blueprint $table) {
            $table->string('thumbnail_path')->nullable()->after('srt_path');
            $table->json('thumbnail_options')->nullable()->after('thumbnail_path');
        });
        Schema::table('english_lessons', function (Blueprint $table) {
            $table->string('thumbnail_path')->nullable()->after('video_path');
        });
        Schema::table('episodes', function (Blueprint $table) {
            $table->json('score')->nullable()->after('sound_error');
            $table->string('score_status', 20)->nullable()->after('score');
            $table->string('score_error', 500)->nullable()->after('score_status');
        });
    }

    public function down(): void
    {
        Schema::table('episode_renders', fn (Blueprint $table) => $table->dropColumn(['thumbnail_path', 'thumbnail_options']));
        Schema::table('english_lessons', fn (Blueprint $table) => $table->dropColumn('thumbnail_path'));
        Schema::table('episodes', fn (Blueprint $table) => $table->dropColumn(['score', 'score_status', 'score_error']));
    }
};
