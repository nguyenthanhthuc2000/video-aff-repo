<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_projects', function (Blueprint $table) {
            $table->string('motion_style')->nullable()->after('fashion_model_id');
        });

        Schema::table('scenes', function (Blueprint $table) {
            $table->string('clip_path')->nullable()->after('image_prompt');
        });
    }

    public function down(): void
    {
        Schema::table('scenes', function (Blueprint $table) {
            $table->dropColumn('clip_path');
        });

        Schema::table('video_projects', function (Blueprint $table) {
            $table->dropColumn('motion_style');
        });
    }
};
