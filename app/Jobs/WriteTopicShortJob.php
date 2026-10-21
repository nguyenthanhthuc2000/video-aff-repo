<?php

namespace App\Jobs;

use App\Models\TopicShort;
use App\Services\Claude\AiUsage;
use App\Services\Script\ScriptWriterException;
use App\Services\TopicShort\ShortWriter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Claude viết lời kể + chia cảnh, rồi tìm ảnh wiki cho từng cảnh. */
class WriteTopicShortJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public TopicShort $short) {}

    public function handle(): void
    {
        $short = $this->short->fresh();
        if ($short === null || $short->status !== 'writing') {
            return;
        }

        try {
            $script = AiUsage::withContext(['user_id' => $short->user_id, 'subject' => "topic_short:{$short->id}"], fn () => ShortWriter::write($short));
        } catch (ScriptWriterException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 120);

                return;
            }
            $this->fail($e);

            return;
        }

        $scenes = DB::transaction(function () use ($short, $script) {
            $short->scenes()->delete();
            $scenes = [];
            foreach ($script['scenes'] as $i => $scene) {
                $scenes[] = $short->scenes()->create([...$scene, 'position' => $i + 1, 'image_status' => 'searching']);
            }
            $short->update([
                'title' => $script['title'],
                'content' => ['caption' => $script['caption'], 'hashtags' => $script['hashtags']],
                // Giữ ảnh mẫu đã tải của nhân vật trùng tên khi viết lại.
                'characters' => collect($script['characters'])->map(fn ($c) => [
                    ...$c,
                    'image_path' => collect($short->characters ?? [])->first(fn ($old) => mb_strtolower($old['name']) === mb_strtolower($c['name']))['image_path'] ?? null,
                ])->all(),
                'art_style' => $script['art_style'],
                'status' => 'ready',
                'error' => null,
            ]);

            return $scenes;
        });

        foreach ($scenes as $scene) {
            FindTopicSceneImageJob::dispatch($scene);
        }
    }

    public function failed(?Throwable $e): void
    {
        $this->short->fresh()?->update(['status' => 'failed', 'error' => mb_substr($e?->getMessage() ?: 'Viết lời kể thất bại.', 0, 500)]);
    }
}
