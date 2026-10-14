<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Bài hát lấy từ link YouTube (dịch vụ audio, yt-dlp): trạng thái lấy về, ngôn ngữ lời, thông tin bài + phân tích của Claude. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audio_tracks', function (Blueprint $table) {
            $table->string('import_status', 20)->nullable()->after('source_url');
            $table->string('import_error', 500)->nullable()->after('import_status');
            $table->string('language', 10)->nullable()->after('lyrics');
            $table->json('song_info')->nullable()->after('language');
        });
    }

    public function down(): void
    {
        Schema::table('audio_tracks', fn (Blueprint $table) => $table->dropColumn(['import_status', 'import_error', 'language', 'song_info']));
    }
};
