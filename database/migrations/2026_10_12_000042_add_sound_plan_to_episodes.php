<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Danh sách âm thanh hiệu ứng của tập (Claude chọn từ kho): [{shot_id|null, place: shot|title|end, sound, at_ms, volume}]. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->json('sound_plan')->nullable()->after('voice_engine');
            $table->string('sound_status', 20)->nullable()->after('sound_plan');
            $table->string('sound_error', 500)->nullable()->after('sound_status');
        });
    }

    public function down(): void
    {
        Schema::table('episodes', fn (Blueprint $table) => $table->dropColumn(['sound_plan', 'sound_status', 'sound_error']));
    }
};
