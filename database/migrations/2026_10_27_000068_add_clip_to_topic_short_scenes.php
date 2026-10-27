<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Video ngắn Muse làm từ ảnh cảnh: khi dựng, cảnh có video dùng video thay ảnh động. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topic_short_scenes', function (Blueprint $table) {
            $table->string('clip_path')->nullable()->after('muse_fix_prompt');
            $table->string('clip_status', 20)->default('none')->after('clip_path');
            $table->string('clip_request_id')->nullable()->after('clip_status');
            $table->text('clip_error')->nullable()->after('clip_request_id');
        });
    }

    public function down(): void
    {
        Schema::table('topic_short_scenes', function (Blueprint $table) {
            $table->dropColumn(['clip_path', 'clip_status', 'clip_request_id', 'clip_error']);
        });
    }
};
