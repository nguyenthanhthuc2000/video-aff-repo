<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sample_videos', function (Blueprint $table) {
            // Phân tích chuyển động (Claude xem các khung hình): dòng thời gian từng nhịp để Muse làm y chang.
            $table->string('analysis_status')->nullable()->after('reference_path');
            $table->json('motion_analysis')->nullable()->after('analysis_status');
            $table->text('analysis_error')->nullable()->after('motion_analysis');
            // Video có tiếng không, và bài nhạc tách từ video (thư viện Âm thanh) để dùng "nhạc như video mẫu".
            $table->boolean('has_audio')->nullable()->after('analysis_error');
            $table->foreignId('audio_track_id')->nullable()->after('has_audio')->constrained('audio_tracks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sample_videos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('audio_track_id');
            $table->dropColumn(['analysis_status', 'motion_analysis', 'analysis_error', 'has_audio']);
        });
    }
};
