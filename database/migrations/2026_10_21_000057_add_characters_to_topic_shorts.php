<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topic_shorts', function (Blueprint $table) {
            // Nhân vật chính: [{name, look, image_path}] — ảnh mẫu gửi kèm cho Muse để vẽ đúng nhân vật.
            $table->json('characters')->nullable()->after('content');
            $table->string('art_style', 300)->nullable()->after('characters');
        });
        Schema::table('topic_short_scenes', function (Blueprint $table) {
            $table->json('characters')->nullable()->after('image_prompt');
        });
    }

    public function down(): void
    {
        Schema::table('topic_short_scenes', fn (Blueprint $table) => $table->dropColumn('characters'));
        Schema::table('topic_shorts', fn (Blueprint $table) => $table->dropColumn(['characters', 'art_style']));
    }
};
