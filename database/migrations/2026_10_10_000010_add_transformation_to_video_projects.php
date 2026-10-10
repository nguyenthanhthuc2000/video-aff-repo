<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_projects', function (Blueprint $table) {
            // standard: video thường; transformation: biến hình (ảnh "trước" đồ cũ, mặt mộc → ảnh "sau" makeup mặc sản phẩm).
            $table->string('video_type')->default('standard')->after('fashion_model_id');
            $table->foreignId('before_fashion_model_id')->nullable()->after('video_type')->constrained('fashion_models')->nullOnDelete();
        });

        Schema::table('scenes', function (Blueprint $table) {
            // Cảnh của video biến hình: before (dùng ảnh "trước") hoặc after (ảnh "sau" mặc sản phẩm). Video thường: null.
            $table->string('phase')->nullable()->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('scenes', fn (Blueprint $table) => $table->dropColumn('phase'));
        Schema::table('video_projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('before_fashion_model_id');
            $table->dropColumn('video_type');
        });
    }
};
