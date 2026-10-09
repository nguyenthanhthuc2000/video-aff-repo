<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenes', function (Blueprint $table) {
            // Clip chuyển động do muse.ai tạo từ ảnh cảnh: trạng thái, lỗi và mã yêu cầu (VA-XXXXXX).
            $table->string('clip_status')->nullable()->after('clip_path');
            $table->text('clip_error')->nullable()->after('clip_status');
            $table->string('clip_request_id')->nullable()->after('clip_error');
        });
    }

    public function down(): void
    {
        Schema::table('scenes', fn (Blueprint $table) => $table->dropColumn(['clip_status', 'clip_error', 'clip_request_id']));
    }
};
