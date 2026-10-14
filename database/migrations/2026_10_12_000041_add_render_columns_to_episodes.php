<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ghép cả tập: trạng thái / tiến độ, tuỳ chọn (nhạc nền, phụ đề, thẻ tiêu đề), file video và phụ đề .srt. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->string('render_status', 20)->nullable()->after('render_path');
            $table->unsignedTinyInteger('render_progress')->default(0)->after('render_status');
            $table->string('render_error', 500)->nullable()->after('render_progress');
            $table->json('render_options')->nullable()->after('render_error');
            $table->string('srt_path')->nullable()->after('render_options');
            $table->timestamp('rendered_at')->nullable()->after('srt_path');
        });
    }

    public function down(): void
    {
        Schema::table('episodes', fn (Blueprint $table) => $table->dropColumn(['render_status', 'render_progress', 'render_error', 'render_options', 'srt_path', 'rendered_at']));
    }
};
