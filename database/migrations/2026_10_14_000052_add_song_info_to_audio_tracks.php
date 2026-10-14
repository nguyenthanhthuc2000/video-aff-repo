<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Bài hát trong thư viện: ngôn ngữ lời (phân tích giọng hát đúng thứ tiếng), thông tin bài + phân tích của Claude cho MV. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audio_tracks', function (Blueprint $table) {
            $table->string('language', 10)->nullable()->after('lyrics');
            $table->json('song_info')->nullable()->after('language');
        });
    }

    public function down(): void
    {
        Schema::table('audio_tracks', fn (Blueprint $table) => $table->dropColumn(['language', 'song_info']));
    }
};
