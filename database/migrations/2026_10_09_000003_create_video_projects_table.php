<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fashion_model_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('draft');
            $table->json('script')->nullable();
            $table->unsignedTinyInteger('selected_hook')->default(0);
            $table->string('music')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('scenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_project_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->unsignedInteger('duration_ms');
            $table->text('visual');
            $table->string('on_screen_text')->nullable();
            $table->text('voiceover')->nullable();
            $table->text('image_prompt');
            $table->timestamps();
        });

        Schema::create('scene_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scene_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->string('path')->nullable();
            $table->string('provider')->nullable();
            $table->string('provider_request_id')->nullable();
            $table->decimal('cost_usd', 8, 4)->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('renders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_project_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('hook');
            $table->string('status')->default('pending');
            $table->string('path')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('renders');
        Schema::dropIfExists('scene_images');
        Schema::dropIfExists('scenes');
        Schema::dropIfExists('video_projects');
    }
};
