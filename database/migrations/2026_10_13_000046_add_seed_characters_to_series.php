<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Nhân vật người tạo phim có sẵn ảnh (nhập lúc tạo phim): [{name, note, path}]; Claude xem ảnh để viết ngoại hình, ảnh thành ảnh chính. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('series', function (Blueprint $table) {
            $table->json('seed_characters')->nullable()->after('idea');
        });
    }

    public function down(): void
    {
        Schema::table('series', fn (Blueprint $table) => $table->dropColumn('seed_characters'));
    }
};
