<?php

namespace App\Jobs;

use App\Enums\SceneClipStatus;
use App\Models\Scene;
use App\Services\Image\ImageGeneratorException;
use App\Services\Image\MuseTask;
use App\Services\Video\ClipGenerator;
use App\Support\Media;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Tạo clip chuyển động cho một cảnh từ ảnh đã duyệt (muse.ai): gửi rồi hỏi lại theo mã, không giữ hàng đợi. */
class GenerateSceneClipJob implements ShouldQueue
{
    use Queueable;

    public const POLL_SECONDS = 15;

    public int $timeout = 300;

    public function __construct(public Scene $scene) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(90);
    }

    public function handle(ClipGenerator $generator): void
    {
        $scene = $this->scene;
        if ($scene->clip_status !== SceneClipStatus::Pending) {
            return;
        }

        $project = $scene->videoProject;
        $imagePath = $scene->approvedImage?->path;
        $style = $project->motionStyleFor($scene);
        if ($imagePath === null || $style === null) {
            $this->fail('Cần ảnh cảnh đã duyệt và kiểu chuyển động.');

            return;
        }

        try {
            if (! MuseTask::isCode($scene->clip_request_id)) {
                $scene->update(['clip_request_id' => $generator->start($imagePath, $style->prompt($scene))]);
                $this->release(30);

                return;
            }

            $clip = $generator->poll($scene->clip_request_id);
            if ($clip === null) {
                $this->release(self::POLL_SECONDS);

                return;
            }
        } catch (ImageGeneratorException $e) {
            if ($e->retryable && Cache::increment("muse-retries:clip:{$scene->id}") <= 2) {
                $scene->update(['clip_request_id' => null]);
                $this->release(60);
            } else {
                $this->fail($e->getMessage());
            }

            return;
        }

        $old = $scene->clip_path;
        $path = "clips/{$project->id}/{$scene->id}-{$scene->clip_request_id}.{$clip['extension']}";
        Media::disk()->put($path, $clip['contents']);
        $scene->update(['clip_path' => $path, 'clip_status' => SceneClipStatus::Ready, 'clip_error' => null]);
        if ($old !== $path) {
            Media::delete($old);
        }

        $project->advanceAutopilot();
    }

    public function failed(?Throwable $e): void
    {
        $this->scene->update([
            'clip_status' => SceneClipStatus::Failed,
            'clip_error' => $e?->getMessage() ?? 'Tạo clip thất bại.',
        ]);

        // Cảnh lỗi clip dùng ảnh tĩnh; chạy ngầm vẫn đi tiếp.
        $this->scene->videoProject->advanceAutopilot();
    }
}
