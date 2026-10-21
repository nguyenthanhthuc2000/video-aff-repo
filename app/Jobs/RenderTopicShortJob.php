<?php

namespace App\Jobs;

use App\Models\TopicShort;
use App\Services\TopicShort\ShortVideo;
use App\Support\CreditPricing;
use App\Support\Credits;
use App\Support\Media;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/** Đọc lời kể bằng giọng AI và dựng short 9:16 (ffmpeg trên máy), trừ Credits khi xong. */
class RenderTopicShortJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1200;

    public function __construct(public TopicShort $short, public string $run) {}

    /** Giá theo độ dài dự kiến: trên 30 giây tính giá dài. */
    public static function price(int $ms): int
    {
        return CreditPricing::settings()['steps'][$ms > 30_000 ? 'render_long' : 'render'];
    }

    public function handle(): void
    {
        $short = $this->short->fresh();
        if ($short === null || $short->video_status !== 'rendering' || ($short->video_options['run'] ?? null) !== $this->run) {
            return;
        }
        $dir = storage_path("app/render-tmp/topic-short-{$short->id}-{$this->run}");
        File::ensureDirectoryExists($dir);
        try {
            $video = ShortVideo::render($short, $dir, $short->video_options ?? []);
            $path = "topic-shorts/{$short->id}/short-{$this->run}.mp4";
            $stream = fopen($video['path'], 'r');
            Media::disk()->writeStream($path, $stream);
            is_resource($stream) && fclose($stream);
            $old = $short->video_path;
            $short->update(['video_path' => $path, 'video_ms' => $video['ms'], 'video_status' => 'done', 'video_error' => null]);
            $old && $old !== $path && Media::delete($old);
            Credits::charge($short->user_id, self::price($video['ms']), $video['ms'] > 30_000 ? 'render_long' : 'render', "topic-short:{$short->id}:{$this->run}", "Short {$short->topic}: dựng video");
        } catch (RuntimeException $e) {
            $this->fail($e);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function failed(?Throwable $e): void
    {
        $short = $this->short->fresh();
        if ($short && ($short->video_options['run'] ?? null) === $this->run) {
            $short->update(['video_status' => 'failed', 'video_error' => mb_substr($e?->getMessage() ?: 'Dựng video thất bại.', 0, 500)]);
        }
    }
}
