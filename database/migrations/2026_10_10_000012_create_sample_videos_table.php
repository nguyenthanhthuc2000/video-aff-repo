<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Video mẫu của người dùng (điệu nhảy, cách tạo dáng...) để sao chép chuyển động sang người mẫu.
        Schema::create('sample_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('path');
            $table->string('thumbnail_path')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('source_url', 2048)->nullable();
            $table->text('notes')->nullable();
            // Người dùng xác nhận có quyền dùng video (video mẫu thường là clip của người khác trên TikTok).
            $table->timestamp('rights_confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sample_videos');
    }
};
