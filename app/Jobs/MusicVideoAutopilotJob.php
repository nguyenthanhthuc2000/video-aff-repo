<?php

namespace App\Jobs;

use App\Enums\SeriesStatus;
use App\Http\Controllers\EpisodeController;
use App\Models\Series;
use App\Models\Shot;
use App\Services\Film\EpisodeRenderer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Chạy tự động một MV ngắn (series Shorts nhạc, mv_options.autopilot): lên ý tưởng xong → viết phân cảnh → vẽ khung hình → làm clip → ghép.
 * Mỗi lượt xem MV đang ở bước nào, bắt đầu bước kế tiếp nếu bước trước xong, rồi hẹn lượt sau. Dừng khi xong, lỗi, hoặc cần người
 * (ca sĩ chưa có ảnh) và ghi lý do vào mv_options.autopilot_note. Các bước dùng đúng job như khi bấm tay (Credits kiểm tra trong job).
 */
class MusicVideoAutopilotJob implements ShouldQueue
{
    use Queueable;

    public const TICK_SECONDS = 60;

    public function __construct(public Series $series) {}

    public function handle(): void
    {
        $series = $this->series->fresh();
        if ($series === null || ! ($series->mv_options['autopilot'] ?? false)) {
            return;
        }
        $result = $this->advance($series);
        if ($result === 'next') {
            self::dispatch($series)->delay(now()->addSeconds(self::TICK_SECONDS));

            return;
        }
        $series->update(['mv_options' => [...$series->mv_options, 'autopilot' => false, 'autopilot_note' => $result]]);
    }

    /** @return string 'next' để chạy lượt sau, hoặc lý do dừng */
    private function advance(Series $series): string
    {
        if ($series->status === SeriesStatus::Failed) {
            return 'Dừng: lên ý tưởng lỗi.';
        }
        if ($series->isBusy() || $series->status === SeriesStatus::Draft) {
            return 'next';
        }
        $missing = $series->characters()->whereNull('main_image_path')->pluck('name');
        if ($missing->isNotEmpty()) {
            return 'Dừng: cần ảnh chính cho '.$missing->implode(', ').' (vẽ / tải ảnh ở trang MV rồi bật chạy tự động lại).';
        }
        $episode = $series->episodes()->first();
        if ($episode === null) {
            return 'Dừng: MV chưa có tập.';
        }
        if ($episode->status === 'failed' || $episode->render_status === 'failed') {
            return 'Dừng: '.($episode->render_error ?? $episode->error ?? 'có bước bị lỗi.');
        }
        if ($episode->render_status === 'done') {
            return 'Xong.';
        }
        if ($episode->render_status === 'rendering' || in_array($episode->status, ['writing', 'keyframing', 'animating'], true)) {
            return 'next';
        }
        if ($episode->status === 'draft') {
            $run = (string) Str::uuid();
            $episode->update(['status' => 'writing', 'error' => null, 'outline' => ['run' => $run]]);
            WriteEpisodeJob::dispatch($episode, $run);

            return 'next';
        }
        $shots = $episode->shots()->get();
        if ($shots->contains('image_status', 'failed') || $shots->contains('clip_status', 'failed')) {
            return 'Dừng: có shot vẽ khung hình / làm clip lỗi, mở trang tập để làm lại rồi bật chạy tự động lại.';
        }
        if (($todo = $shots->where('image_status', 'none'))->isNotEmpty()) {
            $todo->each(fn (Shot $shot) => EpisodeController::queueImage($shot->setRelation('episode', $episode)));
            $episode->update(['status' => 'keyframing']);

            return 'next';
        }
        if (($todo = $shots->where('image_status', 'ready')->whereNull('video_path')->where('clip_status', 'none'))->isNotEmpty()) {
            $todo->each(fn (Shot $shot) => EpisodeController::queueClip($shot));
            $episode->update(['status' => 'animating']);

            return 'next';
        }
        $run = Str::lower(Str::random(8));
        $episode->update([
            'render_status' => 'rendering', 'render_progress' => 0, 'render_error' => null,
            'render_options' => [...EpisodeRenderer::defaults($series), 'run' => $run, 'plan_sounds' => false],
        ]);
        RenderEpisodeJob::dispatch($episode, $run, 'en');

        return 'next';
    }
}
