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
use App\Services\Video\VideoProbe;
use App\Support\CreditPricing;
use App\Support\Credits;
use App\Support\Media;
use App\Support\MotionCopy;
use App\Support\MusePlan;
use App\Support\OneTake;
use App\Support\Voice;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
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
        // Chỉ kiểm tra lúc gửi yêu cầu; đã gửi rồi thì hỏi kết quả theo mã, không cần ảnh nữa.
        if (! MuseTask::isCode($scene->clip_request_id) && ($imagePath === null || ($sample === null && $style === null && ! $freeMotion))) {
            $this->fail('Cần ảnh cảnh đã duyệt và kiểu chuyển động hoặc video mẫu.');

            return;
        }

        try {
            // Đợi Claude phân tích xong video mẫu để gửi kèm dòng thời gian chuyển động.
            if (! MuseTask::isCode($scene->clip_request_id) && $sample?->analysis_status === 'pending') {
                $this->release(20);

                return;
            }

            // Hát nhép theo dòng thời gian trong phân cảnh Muse (lên trước khi viết kịch bản), không tự tách giọng bằng thuật toán.
            // Shot dài: làm lần lượt từng đoạn, đoạn sau nối từ khung cuối đoạn trước.
            $parts = MusePlan::parts($scene);
            $index = min(count($scene->clip_parts ?? []), count($parts) - 1);
            $part = count($parts) > 1 ? $parts[$index] : null;

            if (! MuseTask::isCode($scene->clip_request_id)) {
                $price = CreditPricing::settings()['steps'][$sample ? 'clip_copy' : 'clip_style'];
                if ($message = Credits::shortfall($project->user, $price, 'tạo clip chuyển động')) {
                    $this->fail($message);

                    return;
                }
                if ($sample) {
                    try {
                        // Mỗi cảnh sao chép đoạn tiếp theo của video mẫu (xem Scene::motionSegment).
                        $segment = self::referenceSegment($scene, $part);
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
                        $audioPath = Voice::prepareAudio($scene, $music ?? app(MusicAnalyzer::class), $part);
                    } catch (RuntimeException $e) {
                        $this->fail($e->getMessage());

                        return;
                    }
                }
                $prompt = trim(implode(' ', array_filter([
                    match (true) {
                        $sample !== null => MotionCopy::prompt($scene),
                        $project->one_take => OneTake::prompt($scene, $style),
                        default => MotionStyle::animate($scene, $style),
                    },
                    MusePlan::shotLine($scene),
                    $part !== null ? MusePlan::partLine($scene, $index) : '',
                    Voice::clipLine($scene, $part),
                ])));
                // Đoạn sau của shot dài bắt đầu từ khung cuối đoạn trước.
                $framePath = $index > 0 ? self::lastFrameOf($scene, $index) : null;
                try {
                    $code = $generator->start($framePath ?? $imagePath, $prompt, $sample ? $referencePath : null, $audioPath);
                } finally {
                    if ($audioPath !== null) {
                        Media::delete($audioPath);
                    }
                    if ($framePath !== null) {
                        Media::delete($framePath);
                    }
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
        Credits::charge(
            $project->user_id,
            CreditPricing::settings()['steps'][$sample ? 'clip_copy' : 'clip_style'],
            'clip',
            "clip:{$scene->id}:{$scene->clip_request_id}",
            "Clip cảnh {$scene->position}".($part !== null ? ' (đoạn '.($index + 1).'/'.count($parts).')' : ''),
            $project->id,
        );

        // Shot dài: lưu đoạn vừa xong, còn đoạn thì làm tiếp; đủ đoạn thì ghép thành clip của cảnh.
        if ($part !== null) {
            $done = [...($scene->clip_parts ?? []), ['path' => $path, 'code' => $scene->clip_request_id]];
            if (count($done) < count($parts)) {
                $scene->update(['clip_parts' => $done, 'clip_request_id' => null]);
                $this->release(5);

                return;
            }
            try {
                $path = self::joinParts($scene, array_column($done, 'path'), $clip['extension']);
            } catch (RuntimeException $e) {
                $this->fail($e->getMessage());

                return;
            }
            foreach ($done as $piece) {
                Media::delete($piece['path']);
            }
            $scene->update(['clip_parts' => null]);
        }

        $scene->update(['clip_path' => $path, 'clip_status' => SceneClipStatus::Ready, 'clip_error' => null, 'clip_completed_at' => now()]);
        if ($old !== $path) {
            Media::delete($old);
        }

        // Một cảnh quay liền: khung cuối thành ảnh mở đầu nhịp sau rồi tạo tiếp.
        OneTake::continueAfter($scene->fresh());

        $project->advanceAutopilot();
    }

    /**
     * Đoạn video mẫu cần sao chép cho cả cảnh hoặc cho một đoạn của shot dài (chia đều theo tỉ lệ thời gian trong shot).
     *
     * @return array{start: float, length: float}
     */
    private static function referenceSegment(Scene $scene, ?array $part): array
    {
        $segment = $scene->motionSegment();
        if ($part === null) {
            return ['start' => $segment['start'], 'length' => min(ReferenceClip::MAX_SECONDS, $segment['length'])];
        }
        $scale = $segment['length'] / max(0.1, $scene->duration_ms / 1000);

        return [
            'start' => round($segment['start'] + $part['start'] * $scale, 1),
            'length' => round(max(1.0, min(ReferenceClip::MAX_SECONDS, $part['length'] * $scale)), 1),
        ];
    }

    /** Khung cuối của đoạn trước (đoạn $index - 1) làm ảnh mở đầu đoạn $index; trả đường dẫn media tạm. */
    private static function lastFrameOf(Scene $scene, int $index): string
    {
        $previous = $scene->clip_parts[$index - 1]['path'] ?? throw new ImageGeneratorException('Thiếu đoạn trước của shot dài.');
        $dir = sys_get_temp_dir().'/clip-part-'.uniqid();
        mkdir($dir);
        $clip = "{$dir}/part.".(pathinfo($previous, PATHINFO_EXTENSION) ?: 'mp4');
        $frame = "{$dir}/last.jpg";
        try {
            file_put_contents($clip, (string) Media::disk()->get($previous));
            if (! app(VideoProbe::class)->lastFrame($clip, $frame)) {
                throw new ImageGeneratorException('Không lấy được khung cuối của đoạn trước.');
            }
            $path = "frames/{$scene->video_project_id}/{$scene->id}-part{$index}.jpg";
            Media::disk()->put($path, (string) file_get_contents($frame));

            return $path;
        } finally {
            @unlink($clip);
            @unlink($frame);
            @rmdir($dir);
        }
    }

    /**
     * Ghép các đoạn của shot dài thành một clip (cắt khung đầu trùng với khung cuối đoạn trước để không thấy khựng).
     *
     * @param  list<string>  $paths
     *
     * @throws RuntimeException
     */
    private static function joinParts(Scene $scene, array $paths, string $extension): string
    {
        $dir = sys_get_temp_dir().'/clip-join-'.uniqid();
        mkdir($dir);
        $files = [];
        try {
            $args = [config('videoaff.ffmpeg'), '-loglevel', 'error', '-y'];
            foreach ($paths as $i => $path) {
                $files[] = $file = "{$dir}/{$i}.".(pathinfo($path, PATHINFO_EXTENSION) ?: 'mp4');
                file_put_contents($file, (string) Media::disk()->get($path));
                $args = [...$args, '-i', $file];
            }
            $audio = collect($files)->every(fn (string $file) => app(VideoProbe::class)->hasAudio($file));
            $filters = [];
            $inputs = '';
            foreach ($files as $i => $file) {
                // Từ đoạn thứ 2 bỏ khung đầu (chính là khung cuối đoạn trước).
                $trim = $i > 0 ? 'trim=start_frame=1,setpts=PTS-STARTPTS,' : '';
                $filters[] = "[{$i}:v]{$trim}scale=720:1280:force_original_aspect_ratio=decrease,pad=720:1280:(ow-iw)/2:(oh-ih)/2,setsar=1,fps=30[v{$i}]";
                $inputs .= "[v{$i}]".($audio ? "[{$i}:a]" : '');
            }
            $filters[] = $inputs.'concat=n='.count($files).':v=1:a='.($audio ? 1 : 0).($audio ? '[v][a]' : '[v]');
            $output = "{$dir}/joined.mp4";
            $args = [...$args, '-filter_complex', implode(';', $filters), '-map', '[v]', ...($audio ? ['-map', '[a]', '-c:a', 'aac'] : []), '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '18', '-pix_fmt', 'yuv420p', $output];
            $result = Process::timeout(300)->run($args);
            if (! $result->successful()) {
                throw new RuntimeException('Ghép các đoạn của shot dài lỗi: '.mb_substr($result->errorOutput(), 0, 300));
            }
            $path = "clips/{$scene->video_project_id}/{$scene->id}-long-".uniqid().'.mp4';
            Media::disk()->put($path, (string) file_get_contents($output));

            return $path;
        } finally {
            foreach ([...$files, "{$dir}/joined.mp4"] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
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
