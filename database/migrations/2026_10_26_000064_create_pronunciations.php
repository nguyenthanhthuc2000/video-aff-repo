<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Cách đọc tên riêng tiếng Anh cho giọng Việt (Piper đánh vần chữ Anh như chữ Việt): Goku → "Gô cu". */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pronunciations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('word', 80);
            $table->string('say', 120);
            $table->timestamps();
            $table->unique(['user_id', 'word']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pronunciations');
    }
};
