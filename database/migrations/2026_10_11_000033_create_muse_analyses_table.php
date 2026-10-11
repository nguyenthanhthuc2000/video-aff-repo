<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lưu lại mọi lần Muse phân tích (prompt gửi, câu trả lời gốc, kết quả đã đọc, đầu vào) để xây bộ skill viết prompt.
        // Giữ cả khi dự án bị xoá hay phân tích lại.
        Schema::create('muse_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('video_project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 30)->default('plan');
            $table->string('request_code', 20)->nullable()->index();
            $table->string('status', 20)->default('pending');
            $table->json('context')->nullable();
            $table->longText('prompt')->nullable();
            $table->longText('reply')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        // Phân cảnh đã có trước khi lưu kho: chép sang (không còn prompt / câu trả lời gốc).
        foreach (DB::table('video_projects')->where('muse_plan_status', 'ready')->whereNotNull('muse_plan')->get() as $project) {
            DB::table('muse_analyses')->insert([
                'user_id' => $project->user_id,
                'video_project_id' => $project->id,
                'kind' => 'plan',
                'request_code' => $project->muse_plan_request_id,
                'status' => 'ready',
                'result' => $project->muse_plan,
                'completed_at' => $project->updated_at,
                'created_at' => $project->updated_at,
                'updated_at' => $project->updated_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('muse_analyses');
    }
};
