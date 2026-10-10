<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_transactions', function (Blueprint $table) {
            // Mã của thứ bị trừ ("image:12", "ai:340"...): job chạy lại không trừ hai lần.
            $table->string('reference', 100)->nullable()->after('action');
            $table->unique(['user_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'reference']);
            $table->dropColumn('reference');
        });
    }
};
