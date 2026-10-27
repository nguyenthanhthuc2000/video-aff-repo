<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Kho khung manga thật: người dùng tải trang truyện lên, máy cắt thành từng khung, Claude ghi nhân vật / nội dung từng khung. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manga_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('chapter', 120)->nullable();
            $table->string('name')->nullable();
            $table->string('image_path');
            $table->string('status', 20)->default('processing');
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('manga_panels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('manga_scan_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('image_path');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->json('characters')->nullable();
            $table->text('description')->nullable();
            $table->string('mood', 20)->nullable();
            $table->boolean('useful')->default(true);
            $table->timestamp('tagged_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'useful']);
        });

        // Ảnh cảnh ghép từ khung manga thật (id trong manga_panels, thứ tự trên xuống), ưu tiên hơn khung thư viện nhân vật.
        Schema::table('topic_short_scenes', function (Blueprint $table) {
            $table->json('manga_panels')->nullable()->after('panels');
        });
    }

    public function down(): void
    {
        Schema::table('topic_short_scenes', function (Blueprint $table) {
            $table->dropColumn('manga_panels');
        });
        Schema::dropIfExists('manga_panels');
        Schema::dropIfExists('manga_scans');
    }
};
