<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Bài hát trong thư viện: ngôn ngữ lời (phân tích giọng hát đúng thứ tiếng), thông tin bài + phân tích của Claude cho MV. */
return new class extends Migration
{
    public function up(): void
    {
        // Bỏ qua cột đã có (bản trước của migration này đã chạy ở một số máy).
        Schema::table('audio_tracks', function (Blueprint $table) {
            if (! Schema::hasColumn('audio_tracks', 'language')) {
                $table->string('language', 10)->nullable()->after('lyrics');
            }
            if (! Schema::hasColumn('audio_tracks', 'song_info')) {
                $table->json('song_info')->nullable()->after('language');
            }
        });
    }

    public function down(): void
    {
        Schema::table('audio_tracks', fn (Blueprint $table) => $table->dropColumn(['language', 'song_info']));
    }
};
