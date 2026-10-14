<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mỗi lần ghép / xuất tập là một bản mới (không thay bản cũ): ngang / dọc, có / không lời, tiếng Anh / tiếng Việt.
 * Chuyển các bản đang có của tập sang bảng này.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('episode_renders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('episode_id')->constrained()->cascadeOnDelete();
            $table->string('lang', 5)->default('en');
            $table->json('options')->nullable();
            $table->string('path');
            $table->string('srt_path')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamps();
        });

        foreach (DB::table('episodes')->get() as $episode) {
            foreach (['en' => '', 'vi' => 'vi_'] as $lang => $prefix) {
                $path = $episode->{"{$prefix}render_path"};
                if ($path === null) {
                    continue;
                }
                $at = $episode->{"{$prefix}rendered_at"} ?? now();
                DB::table('episode_renders')->insert([
                    'episode_id' => $episode->id, 'lang' => $lang, 'options' => $episode->{"{$prefix}render_options"},
                    'path' => $path, 'srt_path' => $episode->{"{$prefix}srt_path"}, 'duration_ms' => 0, 'created_at' => $at, 'updated_at' => $at,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('episode_renders');
    }
};
