<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shorts nhạc theo series: ban nhạc / ca sĩ ảo (hư cấu) dùng lại qua nhiều MV ngắn (thành viên có ngoại hình lúc trẻ / lúc già, ảnh chính),
 * MV thuộc ban nào, chữ hiện trên hình của từng shot (tên thành viên, năm...). Bỏ qua phần đã có (chạy lại an toàn).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('virtual_bands')) {
            Schema::create('virtual_bands', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('name', 120);
                $table->string('genre', 100);
                $table->string('era', 40);
                $table->text('lore')->nullable();
                // [{name, role, look, look_old, image_path}]
                $table->json('members')->nullable();
                $table->string('style_preset', 20)->default('cinematic');
                $table->string('status', 20)->default('ready');
                $table->string('error', 500)->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasColumn('series', 'virtual_band_id')) {
            Schema::table('series', function (Blueprint $table) {
                $table->foreignId('virtual_band_id')->nullable()->after('audio_track_id')->constrained()->nullOnDelete();
            });
        }
        if (! Schema::hasColumn('shots', 'label')) {
            Schema::table('shots', function (Blueprint $table) {
                $table->string('label', 120)->nullable()->after('lyric');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shots', 'label')) {
            Schema::table('shots', fn (Blueprint $table) => $table->dropColumn('label'));
        }
        if (Schema::hasColumn('series', 'virtual_band_id')) {
            Schema::table('series', fn (Blueprint $table) => $table->dropConstrainedForeignId('virtual_band_id'));
        }
        Schema::dropIfExists('virtual_bands');
    }
};
