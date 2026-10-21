<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topic_shorts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('topic', 300);
            $table->string('language', 5)->default('vi');
            $table->unsignedSmallInteger('target_seconds')->default(45);
            $table->string('wiki', 30)->default('dragonball');
            $table->string('status')->default('writing');
            $table->text('error')->nullable();
            $table->string('title')->nullable();
            $table->json('content')->nullable();
            $table->string('video_status')->nullable();
            $table->text('video_error')->nullable();
            $table->json('video_options')->nullable();
            $table->string('video_path')->nullable();
            $table->unsignedInteger('video_ms')->nullable();
            $table->timestamps();
        });

        Schema::create('topic_short_scenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topic_short_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->text('narration');
            $table->string('on_screen_text')->nullable();
            $table->string('image_query')->nullable();
            $table->text('image_prompt')->nullable();
            $table->string('image_source', 10)->nullable();
            $table->string('image_status', 12)->default('none');
            $table->string('image_path')->nullable();
            $table->string('image_url', 1000)->nullable();
            $table->text('image_error')->nullable();
            $table->string('request_id')->nullable();
            $table->json('candidates')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topic_short_scenes');
        Schema::dropIfExists('topic_shorts');
    }
};
