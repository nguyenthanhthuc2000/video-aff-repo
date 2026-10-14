<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Khớp khẩu hình: tiếng của clip Muse đổi sang giọng cố định của nhân vật (giữ nhịp Muse), dùng cho video của shot. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shots', function (Blueprint $table) {
            // Bước đang làm của video: voice (đọc lời), muse (Muse làm clip), sync (đổi giọng tiếng Muse cho khớp khẩu hình).
            $table->string('clip_stage', 20)->nullable()->after('clip_status');
            $table->string('synced_audio_path')->nullable()->after('audio_lines');
            $table->string('sync_warning', 300)->nullable()->after('synced_audio_path');
        });
    }

    public function down(): void
    {
        Schema::table('shots', fn (Blueprint $table) => $table->dropColumn(['clip_stage', 'synced_audio_path', 'sync_warning']));
    }
};
