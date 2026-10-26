<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Bộ đăng bài cho short: ảnh bìa (ghép từ ảnh cảnh + chữ lớn), tiêu đề gợi ý, mô tả. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topic_shorts', function (Blueprint $table) {
            $table->string('cover_path')->nullable()->after('video_ms');
            // {scene_id, text}: cảnh làm nền và chữ trên ảnh bìa.
            $table->json('cover')->nullable()->after('cover_path');
            // {status: writing|ready|failed, error, titles: [..], title, description, hashtags: [..]}
            $table->json('publish')->nullable()->after('cover');
        });
    }

    public function down(): void
    {
        Schema::table('topic_shorts', fn (Blueprint $table) => $table->dropColumn(['cover_path', 'cover', 'publish']));
    }
};
