<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sáng tác bài mới lấy cảm hứng từ một bài trên YouTube: thông tin + lời bài tham khảo (chỉ để phân tích), hồ sơ tham khảo Claude rút ra,
 * bài mới Claude viết (lời, prompt phong cách nhạc, ý tưởng MV), mức trùng lời, bước tạo nhạc và bài hát mới trong thư viện Âm thanh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('song_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source_url', 2048);
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('fetching');
            $table->string('error', 500)->nullable();
            $table->json('reference')->nullable();
            $table->json('profile')->nullable();
            $table->json('song')->nullable();
            $table->json('overlap')->nullable();
            $table->string('music_provider', 20)->nullable();
            $table->string('music_status', 20)->nullable();
            $table->string('music_request_id', 100)->nullable();
            $table->string('music_error', 500)->nullable();
            $table->foreignId('audio_track_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('song_drafts');
    }
};
