<?php

namespace App\Jobs;

use App\Enums\MotionStyle;
use App\Enums\SceneClipStatus;
use App\Enums\VoiceMode;
use App\Models\Scene;
use App\Services\Audio\MusicAnalyzer;
use App\Services\Image\ImageGeneratorException;
use App\Services\Image\MuseTask;
use App\Services\Video\ClipGenerator;
use App\Services\Video\ReferenceClip;
use App\Support\CreditPricing;
use App\Support\Credits;
use App\Support\Media;
use App\Support\MotionCopy;
use App\Support\OneTake;
use App\Support\Voice;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
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

    public function handle(ClipGenerator $generator, ReferenceClip $references, ?MusicAnalyzer $music = null): void
    {
        $scene = $this->scene;
        if ($scene->clip_status !== SceneClipStatus::Pending) {
            return;
        }

        $project = $scene->videoProject;
        $imagePath = $scene->approvedImage?->path;
        // Ưu tiên sao chép chuyển động từ video mẫu (của cảnh hoặc của dự án), không có thì dùng kiểu có sẵn.
        $sample = $scene->motionReference();
        $style = $project->motionStyleFor($scene);
        $freeMotion = $project->one_take || $project->voice_mode !== VoiceMode::Music;
        if ($imagePath === null || ($sample === null && $style === null && ! $freeMotion)) {
            $this->fail('Cần ảnh cảnh đã duyệt và kiểu chuyển động hoặc video mẫu.');

            return;
        }

        try {
            // Đợi Claude phân tích xong video mẫu để gửi kèm dòng thời gian chuyển động.
            if (! MuseTask::isCode($scene->clip_request_id) && $sample?->analysis_status === 'pending') {
                $this->release(20);

                return;
            }

            if (! MuseTask::isCode($scene->clip_request_id)) {
                $price = CreditPricing::settings()['steps'][$sample ? 'clip_copy' : 'clip_style'];
                if ($message = Credits::shortfall($project->user, $price, 'tạo clip chuyển động')) {
                    $this->fail($message);

                    return;
                }
                if ($sample) {
                    try {
                        // Mỗi cảnh sao chép đoạn tiếp theo của video mẫu (xem Scene::motionSegment).
                        $segment = $scene->motionSegment();
                        $referencePath = $references->prepare($sample, $segment['start'], $segment['length']);
                    } catch (RuntimeException $e) {
                        // Lỗi xử lý video mẫu (ffmpeg): thử lại cũng vậy, báo ngay.
                        $this->fail($e->getMessage());

                        return;
                    }
                }
                // Hát nhép: gửi kèm đúng đoạn nhạc của cảnh.
                $audioPath = null;
                if ($project->voice_mode === VoiceMode::Sing) {
                    try {
                        $audioPath = Voice::prepareAudio($scene, $music ?? app(MusicAnalyzer::class));
                    } catch (RuntimeException $e) {
                        $this->fail($e->getMessage());

                        return;
                    }
                }
                $prompt = match (true) {
                    $sample !== null => MotionCopy::prompt($scene),
                    $project->one_take => OneTake::prompt($scene, $style),
                    default => MotionStyle::animate($scene, $style),
                }.' '.Voice::clipLine($scene);
                $code = $generator->start($imagePath, $prompt, $sample ? $referencePath : null, $audioPath);
                if ($audioPath !== null) {
                    Media::delete($audioPath);
                }
                $scene->update([
                    'clip_request_id' => $code,
                    'clip_prompt' => $prompt,
                    'clip_started_at' => $scene->clip_completed_at === null && $scene->clip_started_at ? $scene->clip_started_at : now(),
                    'clip_completed_at' => null,
                ]);
                $this->release(30);

                return;
            }

            $clip = $generator->poll($scene->clip_request_id);
            if ($clip === null) {
                $this->release(self::POLL_SECONDS);

                return;
            }
        } catch (ImageGeneratorException $e) {
            // Đang đủ yêu cầu song song: chờ lượt rồi gửi.
            if ($e->busy) {
                $this->release(20);

                return;
            }
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
        $scene->update(['clip_path' => $path, 'clip_status' => SceneClipStatus::Ready, 'clip_error' => null, 'clip_completed_at' => now()]);
        Credits::charge(
            $project->user_id,
            CreditPricing::settings()['steps'][$sample ? 'clip_copy' : 'clip_style'],
            'clip',
            "clip:{$scene->id}:{$scene->clip_request_id}",
            "Clip cảnh {$scene->position}",
            $project->id,
        );
        if ($old !== $path) {
            Media::delete($old);
        }

        // Một cảnh quay liền: khung cuối thành ảnh mở đầu nhịp sau rồi tạo tiếp.
        OneTake::continueAfter($scene->fresh());

        $project->advanceAutopilot();
    }

    public function failed(?Throwable $e): void
    {
        $this->scene->update([
            'clip_status' => SceneClipStatus::Failed,
            'clip_error' => $e?->getMessage() ?? 'Tạo clip thất bại.',
            'clip_completed_at' => now(),
        ]);

        // Cảnh lỗi clip dùng ảnh tĩnh; chạy ngầm vẫn đi tiếp.
        $this->scene->videoProject->advanceAutopilot();
    }
}
