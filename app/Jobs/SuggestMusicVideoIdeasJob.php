<?php

namespace App\Jobs;

use App\Services\Claude\AiUsage;
use App\Services\Film\MusicVideoIdeas;
use App\Services\Script\ScriptWriterException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Claude đề xuất ý tưởng MV chạy nền; kết quả để trong cache theo mã, trang Tạo MV hỏi lại. */
class SuggestMusicVideoIdeasJob implements ShouldQueue
{
    use Queueable;

    public const TTL_MINUTES = 60;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public int $userId, public string $token, public array $input) {}

    public static function key(string $token): string
    {
        return "mv-ideas:{$token}";
    }

    public function handle(): void
    {
        try {
            $ideas = AiUsage::withContext(['user_id' => $this->userId, 'subject' => 'mv-ideas'], fn () => MusicVideoIdeas::suggest($this->input, "mv_ideas:{$this->token}"));
        } catch (ScriptWriterException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release(30);

                return;
            }
            $this->failed($e);

            return;
        }
        Cache::put(self::key($this->token), ['user_id' => $this->userId, 'status' => 'done', 'ideas' => $ideas], now()->addMinutes(self::TTL_MINUTES));
    }

    public function failed(?Throwable $e): void
    {
        Cache::put(self::key($this->token), ['user_id' => $this->userId, 'status' => 'failed', 'error' => mb_substr($e?->getMessage() ?: 'Chưa đề xuất được ý tưởng.', 0, 300)], now()->addMinutes(self::TTL_MINUTES));
    }
}
