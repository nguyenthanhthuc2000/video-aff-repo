<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mỗi lần gọi Claude (CLI hoặc API) một dòng: thao tác, token, chi phí, thời gian. Dùng cho màn hình Chi phí AI.
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('video_project_id')->nullable()->constrained()->nullOnDelete();
            // Đối tượng liên quan khác: "product:5", "scene:12", "sample_video:3".
            $table->string('subject')->nullable();
            $table->string('action', 50)->index();
            // claude_cli (gói Pro/Max, chi phí là ước tính theo giá API) hoặc claude_api (trả theo token thật).
            $table->string('provider', 20);
            $table->string('model', 100)->nullable();
            $table->boolean('success')->default(true);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
