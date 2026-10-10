<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Một cảnh quay liền: các nhịp nối nhau bằng khung cuối của clip trước, xuất video không cắt cảnh.
        Schema::table('video_projects', function (Blueprint $table) {
            $table->boolean('one_take')->default(false)->after('video_type');
        });
    }

    public function down(): void
    {
        Schema::table('video_projects', fn (Blueprint $table) => $table->dropColumn('one_take'));
    }
};
