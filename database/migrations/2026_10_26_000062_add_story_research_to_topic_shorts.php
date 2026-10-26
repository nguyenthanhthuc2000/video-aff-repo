<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Video truyện có tư liệu thật: research web → duyệt tư liệu → kịch bản gắn nguồn, 3 hook, kiểm chứng, trang manga, series. */
return new class extends Migration
{
    public function up(): void
    {
        // Series nhiều phần: dàn ý chia phần, các phần dùng chung bản tư liệu.
        Schema::create('topic_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('topic', 300);
            $table->unsignedTinyInteger('parts');
            // [{title, focus}]
            $table->json('outline')->nullable();
            $table->timestamps();
        });

        Schema::table('topic_shorts', function (Blueprint $table) {
            $table->boolean('use_research')->default(false)->after('wiki');
            // {summary, facts: [{id, kind, era, text, source_url, source_title, characters, enabled, lore_id}], sources: [{url, title}]}
            $table->json('research')->nullable()->after('use_research');
            $table->text('research_notes')->nullable()->after('research');
            // [{style, text}]: 3 câu hook cho cảnh 1, hook_index là câu đang dùng.
            $table->json('hooks')->nullable()->after('research_notes');
            $table->unsignedTinyInteger('hook_index')->nullable()->after('hooks');
            $table->unsignedTinyInteger('series_parts')->default(1)->after('hook_index');
            $table->foreignId('topic_series_id')->nullable()->after('series_parts')->constrained('topic_series')->nullOnDelete();
            $table->unsignedTinyInteger('part')->nullable()->after('topic_series_id');
        });

        Schema::table('topic_short_scenes', function (Blueprint $table) {
            $table->json('fact_ids')->nullable()->after('expression');
            // Kiểm chứng: {status: supported|inferred|unsupported, note}
            $table->json('fact_check')->nullable()->after('fact_ids');
            // Cảm xúc cảnh (nhạc nền, hiệu ứng): tense, sad, epic, funny, calm.
            $table->string('mood', 12)->nullable()->after('fact_check');
            // Trang manga: [{character, pose, camera}], 1–3 khung xếp dọc.
            $table->json('panels')->nullable()->after('mood');
        });

        // Kho tư liệu dùng lại giữa các video (đã được người dùng duyệt).
        Schema::create('lore_facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('franchise', 40)->default('dragonball');
            // Khoá nhân vật thư viện (goku, vegeta...)
            $table->json('characters')->nullable();
            $table->string('era', 120)->nullable();
            $table->string('kind', 10)->default('fact');
            $table->text('text');
            $table->string('source_url', 500)->nullable();
            $table->string('source_title', 300)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'franchise']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lore_facts');
        Schema::table('topic_short_scenes', fn (Blueprint $table) => $table->dropColumn(['fact_ids', 'fact_check', 'mood', 'panels']));
        Schema::table('topic_shorts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('topic_series_id');
            $table->dropColumn(['use_research', 'research', 'research_notes', 'hooks', 'hook_index', 'series_parts', 'part']);
        });
        Schema::dropIfExists('topic_series');
    }
};
