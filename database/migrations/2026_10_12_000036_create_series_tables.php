<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phim hoạt hình nhiều tập (docs/phim-hoat-hinh.md mục 5.1): bộ phim → nhân vật, bối cảnh, tập → shot → các lần tạo ảnh. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('idea');
            $table->string('genre', 40);
            $table->unsignedTinyInteger('age_min');
            $table->unsignedTinyInteger('age_max');
            $table->boolean('made_for_kids');
            $table->string('style', 500);
            $table->string('aspect_ratio', 10)->default('16:9');
            $table->unsignedSmallInteger('episode_count');
            $table->unsignedSmallInteger('episode_seconds')->default(300);
            $table->string('language', 10)->default('en');
            $table->json('subtitle_languages')->nullable();
            $table->string('intro_path')->nullable();
            $table->string('outro_path')->nullable();
            // Kinh thánh bộ phim (Claude viết, người dùng sửa): logline, bối cảnh thế giới, giọng kể, bài học...
            $table->json('bible')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('error', 500)->nullable();
            $table->boolean('autopilot')->default(false);
            $table->timestamps();
        });

        Schema::create('series_characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('series_id')->constrained('series')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('name', 80);
            $table->string('role', 40);
            $table->unsignedTinyInteger('age')->nullable();
            $table->text('personality')->nullable();
            // Mô tả ngoại hình tiếng Anh cố định, gửi kèm mọi ảnh có nhân vật này.
            $table->text('appearance');
            // Giọng TTS: {voice, pitch, speed}.
            $table->json('voice')->nullable();
            $table->string('main_image_path')->nullable();
            $table->string('sheet_path')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('request_id', 40)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('series_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('series_id')->constrained('series')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('name', 80);
            $table->text('description');
            $table->string('image_path')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('request_id', 40)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('episodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('series_id')->constrained('series')->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('title');
            $table->text('synopsis');
            $table->string('lesson', 500)->nullable();
            // Dàn ý tập: các phân đoạn (WriteEpisodeJob).
            $table->json('outline')->nullable();
            // Tóm tắt sau khi xong tập (sổ liên tục cho các tập sau).
            $table->text('summary')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('animatic_path')->nullable();
            $table->string('render_path')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->unique(['series_id', 'number']);
        });

        Schema::create('shots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('episode_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->unsignedSmallInteger('position')->default(0);
            $table->foreignId('series_location_id')->nullable()->constrained()->nullOnDelete();
            $table->json('character_ids')->nullable();
            $table->string('camera', 120)->nullable();
            $table->text('action');
            // Lời thoại: [{character_id|null (dẫn chuyện), line, emotion}].
            $table->json('dialogue')->nullable();
            $table->unsignedInteger('duration_ms')->default(5000);
            $table->boolean('continues_previous')->default(false);
            $table->string('image_status', 20)->default('pending');
            $table->string('image_path')->nullable();
            $table->string('clip_status', 20)->default('pending');
            $table->string('clip_path')->nullable();
            $table->json('clip_parts')->nullable();
            $table->string('audio_path')->nullable();
            $table->string('request_id', 40)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('shot_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shot_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->text('prompt')->nullable();
            $table->string('provider', 40)->nullable();
            $table->string('request_id', 40)->nullable();
            $table->boolean('approved')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shot_images');
        Schema::dropIfExists('shots');
        Schema::dropIfExists('episodes');
        Schema::dropIfExists('series_locations');
        Schema::dropIfExists('series_characters');
        Schema::dropIfExists('series');
    }
};
