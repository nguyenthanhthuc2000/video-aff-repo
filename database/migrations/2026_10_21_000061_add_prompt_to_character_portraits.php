<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('character_portraits', function (Blueprint $table) {
            // Prompt Claude viết lại sau khi AI từ chối (null = prompt mặc định từ thư viện).
            $table->text('prompt')->nullable()->after('expression');
            $table->unsignedTinyInteger('attempts')->default(0)->after('prompt');
        });
    }

    public function down(): void
    {
        Schema::table('character_portraits', fn (Blueprint $table) => $table->dropColumn(['prompt', 'attempts']));
    }
};
