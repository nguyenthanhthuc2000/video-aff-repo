<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('import_status')->nullable()->after('reviews');
            $table->text('import_error')->nullable()->after('import_status');
            // Nội dung trang Shopee chờ phân tích; xoá sau khi xong.
            $table->json('import_snapshot')->nullable()->after('import_error');
        });

        Schema::table('video_projects', function (Blueprint $table) {
            // Tự chạy tiếp các bước (kịch bản → ảnh → xuất video) bằng job nền.
            $table->boolean('autopilot')->default(false)->after('motion_style');
        });

        Schema::table('renders', function (Blueprint $table) {
            $table->unsignedTinyInteger('progress')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('renders', fn (Blueprint $table) => $table->dropColumn('progress'));
        Schema::table('video_projects', fn (Blueprint $table) => $table->dropColumn('autopilot'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['import_status', 'import_error', 'import_snapshot']));
    }
};
