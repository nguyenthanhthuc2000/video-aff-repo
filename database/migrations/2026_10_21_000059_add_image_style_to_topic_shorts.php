<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topic_shorts', function (Blueprint $table) {
            // Kiểu ảnh Muse vẽ / ưu tiên khi tìm: manga (truyện tranh đen trắng), manga_color, anime.
            $table->string('image_style', 20)->default('manga')->after('style');
        });
    }

    public function down(): void
    {
        Schema::table('topic_shorts', fn (Blueprint $table) => $table->dropColumn('image_style'));
    }
};
