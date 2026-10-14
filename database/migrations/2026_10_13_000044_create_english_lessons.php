<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Học tiếng Anh mỗi ngày (A2 → B1): mỗi ngày một bài (5 câu hoặc nói 30 giây), các lần đọc / nói của người học kèm điểm,
 * và video Shorts làm từ bài học + giọng người học.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('english_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('day');
            $table->string('kind', 20);
            $table->string('level', 10);
            $table->string('topic');
            $table->string('topic_vi');
            $table->json('content')->nullable();
            $table->json('feedback')->nullable();
            $table->string('status', 20)->default('writing');
            $table->string('error', 500)->nullable();
            $table->string('video_status', 20)->nullable();
            $table->string('video_error', 500)->nullable();
            $table->json('video_options')->nullable();
            $table->string('video_path')->nullable();
            $table->timestamp('video_rendered_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'day']);
        });

        Schema::create('english_recordings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('english_lesson_id')->constrained()->cascadeOnDelete();
            // Câu thứ mấy (0–4); bài nói 30 giây: 0.
            $table->unsignedTinyInteger('item');
            $table->string('path');
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('status', 20)->default('pending');
            $table->text('transcript')->nullable();
            $table->json('words')->nullable();
            $table->json('segments')->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->index(['english_lesson_id', 'item']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('english_recordings');
        Schema::dropIfExists('english_lessons');
    }
};
