<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Video từng shot: lời thoại đọc bằng Kokoro (giọng cố định của nhân vật) + clip Muse từ khung hình, ghép thành video có tiếng. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shots', function (Blueprint $table) {
            // Tiếng thoại: khoá = nội dung lời + giọng (đổi lời / đổi giọng thì đọc lại), mốc từng câu để khớp môi và phụ đề.
            $table->string('audio_key', 40)->nullable()->after('audio_path');
            $table->unsignedInteger('audio_ms')->nullable()->after('audio_key');
            $table->json('audio_lines')->nullable()->after('audio_ms');
            $table->string('clip_request_id', 40)->nullable()->after('clip_parts');
            $table->string('clip_error', 500)->nullable()->after('clip_request_id');
            $table->string('video_path')->nullable()->after('clip_error');
        });
        // Trạng thái clip: none / pending / ready / failed (trước đây mặc định pending dù chưa làm).
        Schema::table('shots', fn (Blueprint $table) => $table->string('clip_status', 20)->default('none')->change());
        DB::table('shots')->whereNull('clip_path')->where('clip_status', 'pending')->update(['clip_status' => 'none']);
    }

    public function down(): void
    {
        Schema::table('shots', function (Blueprint $table) {
            $table->dropColumn(['audio_key', 'audio_ms', 'audio_lines', 'clip_request_id', 'clip_error', 'video_path']);
        });
    }
};
