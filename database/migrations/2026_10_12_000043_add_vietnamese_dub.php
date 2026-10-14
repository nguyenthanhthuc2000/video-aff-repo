<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bản lồng tiếng Việt (phụ đề tiếng Anh) của tập: lời dịch (Claude), tiếng Việt từng shot, file ghép riêng với bản tiếng Anh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->json('dub_vi')->nullable()->after('sound_error');
            $table->string('vi_render_status', 20)->nullable()->after('dub_vi');
            $table->unsignedTinyInteger('vi_render_progress')->default(0)->after('vi_render_status');
            $table->string('vi_render_error', 500)->nullable()->after('vi_render_progress');
            $table->json('vi_render_options')->nullable()->after('vi_render_error');
            $table->string('vi_render_path')->nullable()->after('vi_render_options');
            $table->string('vi_srt_path')->nullable()->after('vi_render_path');
            $table->timestamp('vi_rendered_at')->nullable()->after('vi_srt_path');
        });
        Schema::table('shots', function (Blueprint $table) {
            $table->string('dub_vi_path')->nullable()->after('sync_warning');
            $table->string('dub_vi_key', 40)->nullable()->after('dub_vi_path');
            $table->json('dub_vi_lines')->nullable()->after('dub_vi_key');
        });
    }

    public function down(): void
    {
        Schema::table('episodes', fn (Blueprint $table) => $table->dropColumn([
            'dub_vi', 'vi_render_status', 'vi_render_progress', 'vi_render_error', 'vi_render_options', 'vi_render_path', 'vi_srt_path', 'vi_rendered_at',
        ]));
        Schema::table('shots', fn (Blueprint $table) => $table->dropColumn(['dub_vi_path', 'dub_vi_key', 'dub_vi_lines']));
    }
};
