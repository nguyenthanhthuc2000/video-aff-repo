<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Truyện ngắn không thoại 9:16 (TikTok / Reels / Shorts): loại phim, người xem, số clip AI mỗi tập, danh sách tập có sẵn;
 * mỗi tập có kịch bản có sẵn (brief), thể loại, gói sản xuất / đăng bài; mỗi shot có chuyển động AI hay ảnh tĩnh + chuyển máy, âm thanh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('series', function (Blueprint $table) {
            $table->string('format', 20)->default('episodic')->after('idea');
            $table->string('audience', 200)->nullable()->after('age_max');
            $table->unsignedTinyInteger('clip_budget')->nullable()->after('episode_seconds');
            $table->json('seed_episodes')->nullable()->after('seed_characters');
        });
        Schema::table('episodes', function (Blueprint $table) {
            $table->string('kind', 20)->nullable()->after('lesson');
            $table->text('brief')->nullable()->after('kind');
            $table->json('package')->nullable()->after('outline');
        });
        Schema::table('shots', function (Blueprint $table) {
            $table->boolean('animate')->default(true)->after('continues_previous');
            $table->text('motion')->nullable()->after('action');
            $table->string('camera_move', 20)->nullable()->after('camera');
            $table->string('sound_cue', 500)->nullable()->after('motion');
        });
    }

    public function down(): void
    {
        Schema::table('shots', fn (Blueprint $table) => $table->dropColumn(['animate', 'motion', 'camera_move', 'sound_cue']));
        Schema::table('episodes', fn (Blueprint $table) => $table->dropColumn(['kind', 'brief', 'package']));
        Schema::table('series', fn (Blueprint $table) => $table->dropColumn(['format', 'audience', 'clip_budget', 'seed_episodes']));
    }
};
