<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lời từ chối của Muse và prompt Claude sửa theo đó, chờ bạn bấm "Tạo lại ảnh". */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topic_short_scenes', function (Blueprint $table) {
            $table->text('muse_refusal')->nullable()->after('image_error');
            $table->text('muse_fix_prompt')->nullable()->after('muse_refusal');
        });
    }

    public function down(): void
    {
        Schema::table('topic_short_scenes', function (Blueprint $table) {
            $table->dropColumn(['muse_refusal', 'muse_fix_prompt']);
        });
    }
};
