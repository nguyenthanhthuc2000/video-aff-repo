<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Claude gợi ý tiêu đề video cho tập (title_ideas: vài phương án + chữ ngắn cho ảnh bìa) và Muse vẽ riêng ảnh nền cho ảnh bìa
 * của từng bản xuất (art_*: gửi rồi hỏi lại theo mã như khung hình shot).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->json('title_ideas')->nullable()->after('score_error');
            $table->string('title_status', 20)->nullable()->after('title_ideas');
            $table->string('title_error', 500)->nullable()->after('title_status');
        });
        Schema::table('episode_renders', function (Blueprint $table) {
            $table->string('art_path')->nullable()->after('thumbnail_options');
            $table->string('art_status', 20)->nullable()->after('art_path');
            $table->string('art_request_id')->nullable()->after('art_status');
            $table->string('art_error', 500)->nullable()->after('art_request_id');
        });
    }

    public function down(): void
    {
        Schema::table('episodes', fn (Blueprint $table) => $table->dropColumn(['title_ideas', 'title_status', 'title_error']));
        Schema::table('episode_renders', fn (Blueprint $table) => $table->dropColumn(['art_path', 'art_status', 'art_request_id', 'art_error']));
    }
};
