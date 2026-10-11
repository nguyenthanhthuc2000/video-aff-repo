<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Phân tích giọng hát (Demucs + Whisper): các khoảng có lời và lời kèm mốc thời gian, dùng cho hát nhép.
        Schema::table('audio_tracks', function (Blueprint $table) {
            $table->string('vocal_status', 20)->nullable()->after('duration_ms');
            $table->json('vocal_analysis')->nullable()->after('vocal_status');
            $table->string('vocal_error', 500)->nullable()->after('vocal_analysis');
        });
    }

    public function down(): void
    {
        Schema::table('audio_tracks', fn (Blueprint $table) => $table->dropColumn(['vocal_status', 'vocal_analysis', 'vocal_error']));
    }
};
