<?php

namespace App\Jobs;

use App\Models\TopicShort;
use App\Models\TopicShortScene;
use App\Services\Claude\AiUsage;
use App\Services\Script\ScriptWriterException;
use App\Services\TopicShort\FactChecker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/** Kiểm chứng lời kể với tư liệu đã duyệt. Lỗi không làm hỏng short: chỉ ghi log, người dùng bấm "Kiểm chứng lại". */
class CheckTopicShortFactsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(public TopicShort $short) {}

    public function failed(): void
    {
        $this->short->scenes()->whereNull('fact_check')->update(['fact_check' => json_encode(TopicShortScene::UNCHECKED)]);
    }

    public function handle(): void
    {
        $short = $this->short->fresh();
        if ($short === null || $short->status !== 'ready' || ! $short->use_research) {
            return;
        }

        try {
            AiUsage::withContext(['user_id' => $short->user_id, 'subject' => "topic_short:{$short->id}"], fn () => FactChecker::check($short));
        } catch (ScriptWriterException $e) {
            Log::warning("Kiểm chứng short {$short->id} thất bại: {$e->getMessage()}");
            $short->scenes()->whereNull('fact_check')->update(['fact_check' => json_encode(TopicShortScene::UNCHECKED)]);
        }
    }
}
