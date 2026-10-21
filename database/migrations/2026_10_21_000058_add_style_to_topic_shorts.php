<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topic_shorts', function (Blueprint $table) {
            // Dạng short: moment (tóm tắt khoảnh khắc), facts (sự thật ít ai biết), versus (giả thuyết đối đầu), top5.
            $table->string('style', 20)->default('moment')->after('language');
        });
    }

    public function down(): void
    {
        Schema::table('topic_shorts', fn (Blueprint $table) => $table->dropColumn('style'));
    }
};
