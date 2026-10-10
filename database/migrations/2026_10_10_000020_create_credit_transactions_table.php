<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Số dư Credits hiện tại (bằng tổng credit_transactions đã hoàn tất).
            $table->integer('credit_balance')->default(0)->after('blocked_at');
        });

        // Sổ Credits: nạp tiền, trừ khi dùng, hoàn lại, admin điều chỉnh. Mỗi dòng ghi số dư sau giao dịch.
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // topup | usage | refund | adjustment
            $table->string('type', 20)->index();
            // completed | pending | failed (nạp qua cổng thanh toán có thể chờ xác nhận).
            $table->string('status', 20)->default('completed');
            // Dương: cộng vào số dư; âm: trừ.
            $table->integer('credits');
            $table->integer('balance_after')->nullable();
            // Nạp tiền: số tiền, gói, phương thức.
            $table->unsignedBigInteger('amount_vnd')->nullable();
            $table->string('package', 30)->nullable();
            $table->string('method', 30)->nullable();
            // Dùng Credits: thao tác và dự án.
            $table->string('action', 50)->nullable();
            $table->foreignId('video_project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 500)->nullable();
            // Admin tạo giao dịch thủ công.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('credit_balance'));
    }
};
