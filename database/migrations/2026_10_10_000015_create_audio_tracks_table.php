<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Thư viện nhạc nền của người dùng: file tải lên / tải từ link, hoặc chỉ lưu link (trang TikTok, YouTube...).
        Schema::create('audio_tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // null = chỉ có link, không dùng làm nhạc nền khi xuất video được.
            $table->string('path')->nullable();
            $table->string('source_url', 2048)->nullable();
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('rights_confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('video_projects', function (Blueprint $table) {
            // Nhạc nền chọn từ thư viện (ưu tiên hơn file "music" tải lên riêng cho dự án ở bản cũ).
            $table->foreignId('audio_track_id')->nullable()->after('music')->constrained('audio_tracks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('video_projects', fn (Blueprint $table) => $table->dropConstrainedForeignId('audio_track_id'));
        Schema::dropIfExists('audio_tracks');
    }
};
