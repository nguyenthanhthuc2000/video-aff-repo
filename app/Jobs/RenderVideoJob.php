<?php

namespace App\Jobs;

use App\Enums\RenderStatus;
use App\Enums\VideoProjectStatus;
use App\Models\Render;
use App\Models\Scene;
use App\Services\Video\RenderScene;
use App\Services\Video\RenderSpec;
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

            $renderScenes = $scenes->map(fn (Scene $scene) => new RenderScene(
                imagePath: $this->copyLocal($scene->approvedImage->path, $workDir, "scene-{$scene->position}"),
                durationMs: $scene->duration_ms,
                title: (string) $scene->on_screen_text,
                subtitle: (string) $scene->voiceover,
                clipPath: $scene->clip_path ? $this->copyLocal($scene->clip_path, $workDir, "clip-{$scene->position}") : null,
            ))->all();

            $sizes = $project->product->details['sizes'] ?? null;
            $spec = new RenderSpec(
                scenes: $renderScenes,
                hook: (string) ($project->script['hooks'][$render->hook] ?? ''),
                outputPath: "{$workDir}/out.mp4",
                endCardTitle: PromoText::callToAction($project->product),
                endCardSubtitle: $sizes ? "Size: {$sizes}" : '',
                // Nhạc từ thư viện Âm thanh, không có thì file tải lên riêng cho dự án (bản cũ).
                musicPath: ($music = $project->audioTrack?->path ?? $project->music) ? $this->copyLocal($music, $workDir, 'music') : null,
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
            Credits::charge($project->user_id, self::price($spec->durationMs()), 'render', "render:{$render->id}", 'Xuất video', $project->id);
        } finally {
            File::deleteDirectory($workDir);
        }
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
