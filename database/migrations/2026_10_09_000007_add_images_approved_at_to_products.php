<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Người dùng đã chọn ảnh giữ lại sau khi lấy từ Shopee; chưa duyệt thì chưa tạo video được.
            $table->timestamp('images_approved_at')->nullable()->after('import_snapshot');
        });

        // Sản phẩm có sẵn coi như đã duyệt ảnh.
        DB::table('products')->update(['images_approved_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('images_approved_at'));
    }
};
