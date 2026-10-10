<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fashion_models', function (Blueprint $table) {
            // after: ảnh đã makeup (video thường, ảnh "sau" của video biến hình); before: mặt mộc, tóc rối, đồ cũ.
            // Người mẫu có sẵn là "after"; đổi loại ở màn hình Người mẫu.
            $table->string('kind')->default('after')->after('name');
        });

        // Người mẫu đang làm ảnh "trước" trong dự án biến hình có sẵn: chuyển sang loại "trước".
        DB::table('fashion_models')
            ->whereIn('id', DB::table('video_projects')->whereNotNull('before_fashion_model_id')->select('before_fashion_model_id'))
            ->update(['kind' => 'before']);
    }

    public function down(): void
    {
        Schema::table('fashion_models', fn (Blueprint $table) => $table->dropColumn('kind'));
    }
};
