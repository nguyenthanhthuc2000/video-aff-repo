<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nhạc cho Shorts: bài hát của ban nhạc ảo (song_drafts gắn ban nhạc, chủ đề; link tham khảo không bắt buộc), nhiều bản nhạc (take) cho mỗi bài
 * để chọn bản hay nhất, và các đoạn hook 15–40 giây đã lưu để làm Shorts. Bỏ qua phần đã có (chạy lại an toàn).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('song_drafts', function (Blueprint $table) {
            if (! Schema::hasColumn('song_drafts', 'virtual_band_id')) {
                $table->foreignId('virtual_band_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('song_drafts', 'topic')) {
                $table->string('topic', 500)->nullable()->after('notes');
            }
            // Bài của ban nhạc không cần link tham khảo.
            $table->string('source_url', 2048)->nullable()->change();
        });

        if (! Schema::hasTable('song_takes')) {
            Schema::create('song_takes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('song_draft_id')->constrained()->cascadeOnDelete();
                $table->foreignId('audio_track_id')->constrained()->cascadeOnDelete();
                $table->string('provider', 20)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('song_clips')) {
            Schema::create('song_clips', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('audio_track_id')->constrained()->cascadeOnDelete();
                $table->foreignId('virtual_band_id')->nullable()->constrained()->nullOnDelete();
                $table->string('name', 120);
                $table->unsignedInteger('start_ms');
                $table->unsignedInteger('end_ms');
                $table->string('kind', 20)->default('custom');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('song_clips');
        Schema::dropIfExists('song_takes');
        Schema::table('song_drafts', function (Blueprint $table) {
            if (Schema::hasColumn('song_drafts', 'virtual_band_id')) {
                $table->dropConstrainedForeignId('virtual_band_id');
            }
            if (Schema::hasColumn('song_drafts', 'topic')) {
                $table->dropColumn('topic');
            }
        });
    }
};
