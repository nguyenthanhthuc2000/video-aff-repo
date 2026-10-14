<?php

namespace App\Jobs;

use App\Models\EnglishLesson;
use App\Services\English\LessonVideo;
use App\Services\Film\Thumbnail;
use App\Support\CreditPricing;
use App\Support\Credits;
use App\Support\Media;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/** Dựng video Shorts của bài học (ffmpeg trên máy), trừ Credits khi xong. */
class RenderEnglishVideoJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public EnglishLesson $lesson, public string $run) {}

    public static function price(): int
    {
        return CreditPricing::settings()['steps']['render'];
    }

    public function handle(): void
    {
        $lesson = $this->lesson->fresh();
        if ($lesson === null || $lesson->video_status !== 'rendering' || ($lesson->video_options['run'] ?? null) !== $this->run) {
            return;
        }
        $dir = storage_path("app/render-tmp/english-{$lesson->id}-{$this->run}");
        File::ensureDirectoryExists($dir);
        try {
            $file = LessonVideo::render($lesson, $dir, $lesson->video_options ?? []);
            $path = "english/{$lesson->user_id}/day-{$lesson->day}/day-{$lesson->day}-{$this->run}.mp4";
            $stream = fopen($file, 'r');
            Media::disk()->writeStream($path, $stream);
            is_resource($stream) && fclose($stream);
            $old = $lesson->video_path;
            $lesson->update(['video_path' => $path, 'video_status' => 'done', 'video_error' => null, 'video_rendered_at' => now()]);
            $old !== $path && Media::delete($old);
            try {
                Thumbnail::forLesson($lesson->fresh());
            } catch (RuntimeException $e) {
                report($e);
            }
            Credits::charge($lesson->user_id, self::price(), 'render', "english-video:{$lesson->id}:{$this->run}", "Tiếng Anh Day {$lesson->day}: dựng video");
        } catch (RuntimeException $e) {
            $this->fail($e);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function failed(?Throwable $e): void
    {
        $lesson = $this->lesson->fresh();
        if ($lesson && ($lesson->video_options['run'] ?? null) === $this->run) {
            $lesson->update(['video_status' => 'failed', 'video_error' => mb_substr($e?->getMessage() ?: 'Dựng video thất bại.', 0, 500)]);
        }
    }
}
