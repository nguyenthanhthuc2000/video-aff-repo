<?php

namespace App\Jobs;

use App\Enums\RenderStatus;
use App\Enums\VideoProjectStatus;
use App\Enums\VoiceMode;
use App\Models\Render;
use App\Models\Scene;
use App\Services\Video\RenderScene;
use App\Services\Video\RenderSpec;
use App\Services\Video\VideoProbe;
use App\Services\Video\VideoRenderer;
use App\Support\CreditPricing;
use App\Support\Credits;
use App\Support\Media;
use App\Support\PromoText;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

class RenderVideoJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    /** Đoạn đứng hình đầu clip AI bỏ đi khi ghép (chỉ khi clip dài hơn cảnh). */
    public const CLIP_LEAD_IN_MS = 400;

    /** Mờ chuyển ở chỗ đổi bộ đồ khi quay liền. */
    public const OUTFIT_CHANGE_MS = 300;

    public function __construct(public Render $render) {}

    public function handle(VideoRenderer $renderer): void
    {
        $render = $this->render;
        $project = $render->videoProject;
        if ($message = Credits::shortfall($project->user, self::price((int) $project->scenes()->sum('duration_ms')), 'xuất video')) {
            $this->fail($message);

            return;
        }
        $workDir = storage_path("app/render-tmp/{$render->id}");
        File::ensureDirectoryExists($workDir);

        try {
            $scenes = $project->scenes()->with('approvedImage')->get();
            if ($scenes->isEmpty() || $scenes->contains(fn (Scene $scene) => $scene->approvedImage === null)) {
                throw new RuntimeException('Mọi cảnh phải có ảnh đã duyệt trước khi xuất video.');
            }

            // Người mẫu nói: giữ tiếng clip, phụ đề là lời nói; hát nhép: nhạc to từ đoạn đã chọn, không phụ đề lời đọc.
            $voice = $project->voice_mode;
            // Chỉ nhạc nền và không quay liền: bỏ đoạn đứng hình đầu clip (nói / hát nhép cần giữ đúng từ đầu clip).
            $trimStart = $voice === VoiceMode::Music && ! $project->one_take;
            $clipFps = [];
            $renderScenes = $scenes->map(function (Scene $scene) use ($workDir, $voice, $trimStart, $project, &$clipFps) {
                $clip = $scene->clip_path ? $this->copyLocal($scene->clip_path, $workDir, "clip-{$scene->position}") : null;
                $info = $clip ? app(VideoProbe::class)->probe($clip) : null;
                $clipMs = $trimStart ? ($info['duration_ms'] ?? 0) : 0;
                $clip && $clipFps[] = $info['fps'] ?? null;

                return new RenderScene(
                    imagePath: $this->copyLocal($scene->approvedImage->path, $workDir, "scene-{$scene->position}"),
                    durationMs: $scene->duration_ms,
                    title: (string) $scene->on_screen_text,
                    subtitle: $voice === VoiceMode::Sing ? '' : (string) $scene->voiceover,
                    clipPath: $clip,
                    clipHasAudio: $clip !== null && $voice === VoiceMode::Speak && app(VideoProbe::class)->hasAudio($clip) === true,
                    clipOffsetMs: max(0, min(self::CLIP_LEAD_IN_MS, $clipMs - $scene->duration_ms)),
                    // Quay liền nhiều bộ: chỗ đổi bộ là clip mới (khác dáng, mở đầu đứng hình) nên mờ chuyển nhanh thay vì cắt cụt.
                    transitionInMs: $project->one_take && $scene->position > 1 && $project->startsTake($scene) ? self::OUTFIT_CHANGE_MS : 0,
                );
            })->all();

            $sizes = $project->product->details['sizes'] ?? null;
            $spec = new RenderSpec(
                scenes: $renderScenes,
                hook: (string) ($project->script['hooks'][$render->hook] ?? ''),
                outputPath: "{$workDir}/out.mp4",
                endCardTitle: PromoText::callToAction($project->product),
                endCardSubtitle: $sizes ? "Size: {$sizes}" : '',
                // Nhạc từ thư viện Âm thanh, không có thì file tải lên riêng cho dự án (bản cũ).
                withText: $render->with_text,
                musicPath: ($music = $project->audioTrack?->path ?? $project->music) ? $this->copyLocal($music, $workDir, 'music') : null,
                clipAudio: $voice === VoiceMode::Speak,
                musicVolume: $voice === VoiceMode::Sing ? 1.0 : null,
                musicStartMs: $voice === VoiceMode::Sing ? (int) $project->music_start_ms : 0,
                fps: self::outputFps($clipFps),
            );

            // Ghi tiến độ tối đa mỗi 5% để không ghi DB quá nhiều.
            $last = 0;
            $renderer->render($spec, function (int $percent) use ($render, &$last) {
                if ($percent >= $last + 5) {
                    $last = $percent;
                    $render->update(['progress' => min(99, $percent)]);
                }
            });

            $path = "renders/{$project->id}/{$render->id}.mp4";
            $stream = fopen($spec->outputPath, 'r');
            Media::disk()->writeStream($path, $stream);
            is_resource($stream) && fclose($stream);

            $render->update([
                'status' => RenderStatus::Done,
                'progress' => 100,
                'path' => $path,
                'duration_ms' => $spec->durationMs(),
                'error' => null,
                'completed_at' => now(),
            ]);
            $project->update(['status' => VideoProjectStatus::Done, 'error' => null, 'autopilot' => false]);
            // Video xong mà chưa có đề xuất caption đăng bài (dự án cũ / lần trước lỗi) thì viết luôn.
            if ($project->script !== null && in_array($project->post_captions_status, [null, 'failed'], true)) {
                $project->queuePostCaptions();
            }
            Credits::charge($project->user_id, self::price($spec->durationMs()), 'render', "render:{$render->id}", 'Xuất video', $project->id);
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    /**
     * Mọi clip cùng fps (clip Muse 24 khung/giây) thì xuất đúng fps đó: đổi 24 → 30 phải lặp 1 khung mỗi 4 khung, chuyển động bị khựng.
     *
     * @param  list<?float>  $clipFps
     */
    public static function outputFps(array $clipFps): ?int
    {
        $rates = array_unique(array_map(fn ($fps) => $fps ? (int) round($fps) : 0, $clipFps));

        return count($rates) === 1 && $rates[0] >= 20 && $rates[0] <= 60 ? $rates[0] : null;
    }

    /** Giá xuất video theo độ dài (trên 30 giây đắt hơn). */
    public static function price(int $durationMs): int
    {
        return CreditPricing::settings()['steps'][$durationMs > 30000 ? 'render_long' : 'render'];
    }

    public function failed(?Throwable $e): void
    {
        $message = 'Xuất video thất bại: '.($e?->getMessage() ?? 'lỗi không rõ');

        $this->render->update(['status' => RenderStatus::Failed, 'error' => $message, 'completed_at' => now()]);
        $this->render->videoProject->markFailed($message);
    }

    private function copyLocal(string $mediaPath, string $dir, string $name): string
    {
        $contents = Media::disk()->get($mediaPath);
        if ($contents === null) {
            throw new RuntimeException("Không tìm thấy file {$mediaPath}.");
        }

        $local = "{$dir}/{$name}.".(pathinfo($mediaPath, PATHINFO_EXTENSION) ?: 'bin');
        File::put($local, $contents);

        return $local;
    }
}
