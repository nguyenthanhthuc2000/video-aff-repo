<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Chèn chữ (hook, chữ trên cảnh, phụ đề, cảnh kết) khi xuất video; dự án nhớ lựa chọn cho lần xuất / chạy ngầm sau.
        Schema::table('video_projects', function (Blueprint $table) {
            $table->boolean('render_text')->default(true)->after('selected_hook');
        });
        Schema::table('renders', function (Blueprint $table) {
            $table->boolean('with_text')->default(true)->after('hook');
        });
    }

    public function down(): void
    {
        Schema::table('video_projects', fn (Blueprint $table) => $table->dropColumn('render_text'));
        Schema::table('renders', fn (Blueprint $table) => $table->dropColumn('with_text'));
    }
};
