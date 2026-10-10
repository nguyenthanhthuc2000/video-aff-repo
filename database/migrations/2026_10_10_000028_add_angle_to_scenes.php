<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Góc quay sản phẩm của cảnh (toàn thân, cận chi tiết, mặt sau...), xem App\Support\ProductCoverage.
        Schema::table('scenes', function (Blueprint $table) {
            $table->string('angle', 40)->nullable()->after('phase');
        });
    }

    public function down(): void
    {
        Schema::table('scenes', fn (Blueprint $table) => $table->dropColumn('angle'));
    }
};
