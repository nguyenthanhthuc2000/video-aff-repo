<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Giọng tự thu cho từng cảnh (mp3...): khi dựng video dùng file này thay giọng máy. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topic_short_scenes', function (Blueprint $table) {
            $table->string('voice_path')->nullable()->after('panels');
            $table->unsignedInteger('voice_ms')->nullable()->after('voice_path');
        });
    }

    public function down(): void
    {
        Schema::table('topic_short_scenes', function (Blueprint $table) {
            $table->dropColumn(['voice_path', 'voice_ms']);
        });
    }
};
