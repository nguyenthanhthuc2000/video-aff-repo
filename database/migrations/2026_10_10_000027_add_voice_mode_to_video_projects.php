<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lời thoại: chỉ nhạc nền / người mẫu tự nói (review) / hát nhép theo nhạc; music_start_ms: đoạn nhạc dùng khi hát nhép.
        Schema::table('video_projects', function (Blueprint $table) {
            $table->string('voice_mode', 20)->default('music')->after('one_take');
            $table->unsignedInteger('music_start_ms')->nullable()->after('audio_track_id');
        });
    }

    public function down(): void
    {
        Schema::table('video_projects', fn (Blueprint $table) => $table->dropColumn(['voice_mode', 'music_start_ms']));
    }
};
