<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Thư viện bối cảnh của người dùng (phòng thay đồ, shop, góc nhà...): ảnh nền để AI đặt người mẫu vào.
        Schema::create('backgrounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Mô tả ngắn (tiếng Việt) để AI viết kịch bản hợp bối cảnh.
            $table->string('description', 500)->nullable();
            $table->string('photo_path');
            $table->timestamps();
        });

        Schema::table('video_projects', function (Blueprint $table) {
            // null = AI tự đề xuất bối cảnh theo sản phẩm; danh sách id = dùng bối cảnh của người dùng, các cảnh dùng lần lượt.
            $table->json('background_ids')->nullable()->after('sample_video_id');
        });

        Schema::table('scenes', function (Blueprint $table) {
            $table->foreignId('background_id')->nullable()->after('phase')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('scenes', fn (Blueprint $table) => $table->dropConstrainedForeignId('background_id'));
        Schema::table('video_projects', fn (Blueprint $table) => $table->dropColumn('background_ids'));
        Schema::dropIfExists('backgrounds');
    }
};
