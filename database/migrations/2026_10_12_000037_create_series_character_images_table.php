<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Thư viện ảnh của nhân vật: Muse vẽ hoặc người dùng tải lên; một ảnh được chọn làm ảnh chính (tham chiếu cho mọi shot).
        Schema::create('series_character_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('series_character_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20)->default('main');
            $table->string('source', 20);
            $table->string('path')->nullable();
            $table->text('prompt')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('request_id', 40)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();
        });

        // Nhân vật xuất hiện trong tập (chọn trên trang tập; Claude dùng khi viết tập).
        Schema::table('episodes', function (Blueprint $table) {
            $table->json('character_ids')->nullable()->after('lesson');
        });
    }

    public function down(): void
    {
        Schema::table('episodes', fn (Blueprint $table) => $table->dropColumn('character_ids'));
        Schema::dropIfExists('series_character_images');
    }
};
