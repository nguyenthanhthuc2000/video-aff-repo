<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Thư viện nhân vật Ngọc Rồng: mỗi nhân vật × biểu cảm một ảnh (Muse vẽ hoặc tải lên).
        Schema::create('character_portraits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('character', 40);
            $table->string('expression', 4);
            $table->string('status', 12)->default('pending');
            $table->string('source', 10)->nullable();
            $table->string('request_id')->nullable();
            $table->string('image_path')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'character', 'expression']);
        });
        Schema::table('topic_short_scenes', function (Blueprint $table) {
            $table->string('expression', 4)->nullable()->after('characters');
        });
    }

    public function down(): void
    {
        Schema::table('topic_short_scenes', fn (Blueprint $table) => $table->dropColumn('expression'));
        Schema::dropIfExists('character_portraits');
    }
};
