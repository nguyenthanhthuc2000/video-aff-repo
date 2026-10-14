<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MV ca nhạc: bộ phim một tập dựng theo một bài hát trong thư viện Âm thanh (đoạn bài hát dùng, lời bài hát chuẩn, lựa chọn của MV);
 * mỗi shot nằm ở một mốc của bài hát, có câu hát của đoạn đó và có hát nhép hay không.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('series', function (Blueprint $table) {
            $table->foreignId('audio_track_id')->nullable()->after('format')->constrained()->nullOnDelete();
            $table->unsignedInteger('song_start_ms')->nullable()->after('audio_track_id');
            $table->unsignedInteger('song_end_ms')->nullable()->after('song_start_ms');
            $table->text('lyrics')->nullable()->after('song_end_ms');
            // Lựa chọn của MV: hát nhép (cả bài / điệp khúc / không), công cụ tạo ảnh + clip, mô tả ca sĩ, phong cách chọn nhanh.
            $table->json('mv_options')->nullable()->after('lyrics');
        });
        Schema::table('shots', function (Blueprint $table) {
            $table->unsignedInteger('song_ms')->nullable()->after('duration_ms');
            $table->text('lyric')->nullable()->after('song_ms');
            $table->boolean('lip_sync')->default(false)->after('lyric');
        });
    }

    public function down(): void
    {
        Schema::table('shots', fn (Blueprint $table) => $table->dropColumn(['song_ms', 'lyric', 'lip_sync']));
        Schema::table('series', function (Blueprint $table) {
            $table->dropConstrainedForeignId('audio_track_id');
            $table->dropColumn(['song_start_ms', 'song_end_ms', 'lyrics', 'mv_options']);
        });
    }
};
