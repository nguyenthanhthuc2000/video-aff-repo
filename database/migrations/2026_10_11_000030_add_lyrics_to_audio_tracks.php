<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lời bài hát chuẩn (người dùng dán): thay chữ Whisper nghe nhầm, giữ mốc thời gian.
        Schema::table('audio_tracks', function (Blueprint $table) {
            $table->text('lyrics')->nullable()->after('vocal_error');
        });
    }

    public function down(): void
    {
        Schema::table('audio_tracks', fn (Blueprint $table) => $table->dropColumn('lyrics'));
    }
};
