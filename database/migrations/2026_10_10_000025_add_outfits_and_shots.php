<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Các shot (đoạn giữa hai lần cắt cảnh) của video mẫu: [{start, end}] giây.
        Schema::table('sample_videos', function (Blueprint $table) {
            $table->json('shots')->nullable()->after('motion_analysis');
        });
        // Mỗi cảnh một bộ đồ: danh sách bộ đồ của dự án [{product_id, product_image_id, color}], các cảnh mặc lần lượt.
        Schema::table('video_projects', function (Blueprint $table) {
            $table->json('outfits')->nullable()->after('background_ids');
        });
        // Bộ đồ của cảnh; null = sản phẩm của dự án, mọi ảnh sản phẩm.
        Schema::table('scenes', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('background_id')->constrained()->nullOnDelete();
            $table->foreignId('product_image_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
            $table->string('outfit_color', 80)->nullable()->after('product_image_id');
        });
    }

    public function down(): void
    {
        Schema::table('scenes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_image_id');
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn('outfit_color');
        });
        Schema::table('video_projects', fn (Blueprint $table) => $table->dropColumn('outfits'));
        Schema::table('sample_videos', fn (Blueprint $table) => $table->dropColumn('shots'));
    }
};
