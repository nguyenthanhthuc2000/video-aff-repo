<?php

use Database\Seeders\ProductCategorySeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Master data gợi ý theo loại sản phẩm: từ khoá nhận diện (theo tên), bối cảnh quay và kiểu chuyển động.
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            // Loại phụ (ví dụ "Đầm dự tiệc" thuộc "Quần áo"): bối cảnh riêng, thiếu gì thì lấy của loại chính.
            $table->foreignId('parent_id')->nullable()->constrained('product_categories')->cascadeOnDelete();
            $table->string('key')->unique();
            $table->string('name');
            $table->json('keywords');
            $table->json('scene_settings')->nullable();
            $table->json('motion_styles')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Nạp dữ liệu mặc định ngay khi migrate (không cần chạy seeder riêng).
        (new ProductCategorySeeder)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('product_categories');
    }
};
