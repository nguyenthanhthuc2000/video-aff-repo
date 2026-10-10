<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admin xem lại prompt đã gửi và thời gian xử lý từng bước.
        Schema::table('scene_images', function (Blueprint $table) {
            $table->text('prompt')->nullable()->after('provider_request_id');
            $table->timestamp('completed_at')->nullable()->after('error');
        });
        Schema::table('scenes', function (Blueprint $table) {
            $table->text('clip_prompt')->nullable()->after('clip_request_id');
            $table->timestamp('clip_started_at')->nullable()->after('clip_prompt');
            $table->timestamp('clip_completed_at')->nullable()->after('clip_started_at');
        });
        Schema::table('renders', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('scene_images', fn (Blueprint $table) => $table->dropColumn(['prompt', 'completed_at']));
        Schema::table('scenes', fn (Blueprint $table) => $table->dropColumn(['clip_prompt', 'clip_started_at', 'clip_completed_at']));
        Schema::table('renders', fn (Blueprint $table) => $table->dropColumn('completed_at'));
    }
};
