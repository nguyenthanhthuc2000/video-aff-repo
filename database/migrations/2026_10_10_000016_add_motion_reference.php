<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sample_videos', function (Blueprint $table) {
            // Bản rút gọn gửi kèm Muse khi sao chép chuyển động (tối đa 10 giây, 720p, không tiếng).
            $table->string('reference_path')->nullable()->after('thumbnail_path');
        });

        Schema::table('video_projects', function (Blueprint $table) {
            // Video mẫu mặc định để sao chép chuyển động cho mọi cảnh (null = dùng kiểu chuyển động có sẵn).
            $table->foreignId('sample_video_id')->nullable()->after('motion_styles')->constrained('sample_videos')->nullOnDelete();
        });

        Schema::table('scenes', function (Blueprint $table) {
            // Video mẫu riêng của cảnh (null = theo dự án).
            $table->foreignId('sample_video_id')->nullable()->after('clip_request_id')->constrained('sample_videos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('scenes', fn (Blueprint $table) => $table->dropConstrainedForeignId('sample_video_id'));
        Schema::table('video_projects', fn (Blueprint $table) => $table->dropConstrainedForeignId('sample_video_id'));
        Schema::table('sample_videos', fn (Blueprint $table) => $table->dropColumn('reference_path'));
    }
};
