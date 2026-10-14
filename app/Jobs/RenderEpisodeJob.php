<?php

namespace App\Jobs;

use App\Models\Episode;
use App\Services\Claude\AiUsage;
use App\Services\Film\EpisodeRenderer;
use App\Services\Film\EpisodeTitler;
use App\Services\Film\EpisodeTranslator;
use App\Services\Film\ShotDub;
use App\Services\Film\SoundPlanner;
use App\Services\Film\Thumbnail;
use App\Services\Script\ScriptWriterException;
use App\Support\CreditPricing;
use App\Support\Credits;
use App\Support\Media;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Ghép cả tập theo bước (mỗi lượt job vài phút, không vượt thời gian chờ của hàng đợi): mã hoá dần từng đoạn, rồi nối + hoàn thiện.
 * Thư mục làm việc giữ giữa các lượt; lượt cũ (đã bấm ghép lại) tự dừng.
 * Bản tiếng Việt (lang = vi, cột vi_*): trước khi ghép, Claude dịch lời (nếu lời tiếng Anh đổi) và lồng tiếng Việt từng shot;
 * phụ đề tiếng Anh luôn chèn vào hình.
 */
class RenderEpisodeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $timeout = 900;

    /** Mỗi lượt mã hoá đoạn tối đa chừng này giây rồi nhường hàng đợi. */
    public const SLICE_SECONDS = 240;

    public function __construct(public Episode $episode, public string $run, public string $lang = 'en') {}

    /** Tên cột theo bản: bản tiếng Việt dùng các cột vi_*. */
    public static function column(string $lang, string $name): string
    {
        return $lang === 'vi' ? "vi_{$name}" : $name;
    }

    /** Bước đang làm của bản tiếng Việt (hiện trên nút). */
    public static function stage(Episode $episode): ?string
    {
        return Cache::get("episode-render-stage:{$episode->id}");
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(3);
    }

    public static function price(): int
    {
        return CreditPricing::settings()['steps']['render_long'];
    }

    public static function workDir(Episode $episode, string $run): string
    {
        return storage_path("app/render-tmp/episode-{$episode->id}-{$run}");
    }

    private function col(string $name): string
    {
        return self::column($this->lang, $name);
    }

    public function handle(): void
    {
        $episode = $this->episode->fresh();
        if ($episode === null || $episode->{$this->col('render_status')} !== 'rendering' || ($episode->{$this->col('render_options')}['run'] ?? null) !== $this->run) {
            return;
        }
        $dir = self::workDir($episode, $this->run);
        File::ensureDirectoryExists($dir);
        $options = $episode->{$this->col('render_options')};
        $vi = $this->lang === 'vi';

        try {
            // Tên tập còn là chữ tạm ("[Episode Title]"): thẻ tiêu đề / ảnh bìa không được in chữ tạm, đặt tên trước khi ghép.
            if (EpisodeTitler::placeholder($episode->title)) {
                try {
                    AiUsage::withContext(['user_id' => $episode->series->user_id, 'subject' => "series:{$episode->series_id}"], fn () => EpisodeTitler::ensure($episode, true));
                } catch (ScriptWriterException $e) {
                    if ($e->retryable && $this->attempts() < 3) {
                        $this->release(60);

                        return;
                    }
                    Log::warning('render.title_failed', ['episode_id' => $episode->id, 'error' => $e->getMessage()]);
                }
                $episode->refresh();
            }
            // Bước 0: chưa có danh sách âm thanh mà có bật hiệu ứng → Claude chọn trước (một lượt Claude).
            if (($options['plan_sounds'] ?? false) && empty($episode->sound_plan)) {
                try {
                    $sounds = AiUsage::withContext(
                        ['user_id' => $episode->series->user_id, 'subject' => "series:{$episode->series_id}"],
                        fn () => SoundPlanner::plan($episode),
                    );
                } catch (ScriptWriterException $e) {
                    if ($e->retryable) {
                        $this->release(60);

                        return;
                    }
                    $episode->update(['sound_status' => 'failed', 'sound_error' => mb_substr($e->getMessage(), 0, 500)]);
                    throw new RuntimeException('Chọn âm thanh lỗi: '.$e->getMessage(), 0, $e);
                }
                $episode->update(['sound_plan' => $sounds, 'sound_status' => 'ready', 'sound_error' => null]);
                $episode->refresh();
            }

            // Nhạc riêng của tập mà chưa sáng tác: Claude sáng tác trước (một lượt Claude).
            if (($options['original_score'] ?? false) && empty($episode->score['title'])) {
                $episode->update(['score_status' => 'composing', 'score_error' => null]);
                try {
                    $episode->update(['score' => ComposeScoreJob::compose($episode), 'score_status' => 'ready']);
                } catch (ScriptWriterException $e) {
                    if ($e->retryable) {
                        $this->release(60);

                        return;
                    }
                    $episode->update(['score_status' => 'failed', 'score_error' => mb_substr($e->getMessage(), 0, 500)]);
                    throw new RuntimeException('Sáng tác nhạc lỗi: '.$e->getMessage(), 0, $e);
                }
                $episode->refresh();
            }

            if ($vi && ! $this->dub($episode, $options)) {
                return;
            }
            Cache::forget("episode-render-stage:{$episode->id}");

            $planFile = "{$dir}/plan.json";
            if (! File::exists($planFile)) {
                File::put($planFile, json_encode(EpisodeRenderer::plan($episode, $options)));
            }
            $plan = json_decode(File::get($planFile), true);
            $count = count($plan);

            // Mã hoá dần các đoạn chưa có.
            $started = time();
            foreach ($plan as $i => $segment) {
                $file = sprintf('%s/seg-%04d.mp4', $dir, $i);
                if (File::exists($file)) {
                    continue;
                }
                if (time() - $started > self::SLICE_SECONDS) {
                    $this->release(1);

                    return;
                }
                $work = "{$dir}/work-{$i}";
                File::ensureDirectoryExists($work);
                try {
                    EpisodeRenderer::segment($episode, $segment, "{$file}.part.mp4", $work);
                } finally {
                    File::deleteDirectory($work);
                }
                File::move("{$file}.part.mp4", $file);
                $episode->update([$this->col('render_progress') => $vi ? 40 + (int) floor(($i + 1) * 50 / max(1, $count)) : (int) floor(($i + 1) * 85 / max(1, $count))]);
            }

            // Nối + nhạc nền + phụ đề.
            $srt = EpisodeRenderer::srt($episode, $plan);
            File::put("{$dir}/subtitles.srt", $srt);
            $output = EpisodeRenderer::finish($episode, array_map(fn ($i) => sprintf('%s/seg-%04d.mp4', $dir, $i), array_keys($plan)), $options, "{$dir}/subtitles.srt", $dir, $plan);

            $base = "series/{$episode->series_id}/episodes/{$episode->id}/episode-{$episode->number}".($vi ? '-vi' : '')."-{$this->run}";
            $stream = fopen($output, 'r');
            Media::disk()->writeStream("{$base}.mp4", $stream);
            is_resource($stream) && fclose($stream);
            Media::disk()->put("{$base}.srt", $srt);
            $durationMs = (int) round(EpisodeRenderer::duration($output) * 1000);
            // Mỗi lần ghép là một bản mới trong danh sách (bản cũ giữ nguyên); cột render_path của tập trỏ bản mới nhất.
            $render = $episode->renders()->create([
                'lang' => $this->lang, 'options' => collect($options)->except(['run', 'plan_sounds'])->all(),
                'path' => "{$base}.mp4", 'srt_path' => "{$base}.srt", 'duration_ms' => $durationMs,
            ]);
            // Ảnh bìa YouTube cùng khổ với video (lỗi ảnh bìa không làm hỏng bản xuất: tạo lại được trên trang tập).
            try {
                Thumbnail::forRender($render);
            } catch (RuntimeException $e) {
                report($e);
            }
            $episode->update([
                $this->col('render_path') => "{$base}.mp4", $this->col('srt_path') => "{$base}.srt", $this->col('render_status') => 'done',
                $this->col('render_progress') => 100, $this->col('render_error') => null, $this->col('rendered_at') => now(),
            ]);
            Credits::charge(
                $episode->series->user_id,
                self::price(),
                'render',
                "episode-render:{$episode->id}:{$this->run}",
                "Phim {$episode->series->title}: ghép tập {$episode->number}".($vi ? ' bản tiếng Việt' : '').' ('.gmdate('i:s', intdiv($durationMs, 1000)).')',
            );
            File::deleteDirectory($dir);
        } catch (RuntimeException $e) {
            $this->fail($e);
        }
    }

    /**
     * Bản tiếng Việt: dịch lời (Claude, khi lời tiếng Anh đổi) rồi lồng tiếng từng shot; true khi xong, false khi đã hẹn lượt sau.
     *
     * @throws RuntimeException
     */
    private function dub(Episode $episode, array $options): bool
    {
        $stage = "episode-render-stage:{$episode->id}";
        if (! EpisodeTranslator::isCurrent($episode)) {
            Cache::put($stage, 'Claude đang dịch lời sang tiếng Việt...', now()->addHours(8));
            try {
                $dub = AiUsage::withContext(
                    ['user_id' => $episode->series->user_id, 'subject' => "series:{$episode->series_id}"],
                    fn () => EpisodeTranslator::translate($episode),
                );
            } catch (ScriptWriterException $e) {
                if ($e->retryable) {
                    // Thử lại tới hạn retryUntil (lượt job được hẹn lại nhiều lần nên không đếm attempts).
                    $this->release(60);

                    return false;
                }
                throw new RuntimeException('Dịch lời lỗi: '.$e->getMessage(), 0, $e);
            }
            $episode->update(['dub_vi' => $dub, 'vi_render_progress' => 5]);
        }

        $mode = isset(ShotDub::MODES[$options['vi_voice'] ?? '']) ? $options['vi_voice'] : 'plain';
        $shots = $episode->shots()->get();
        $done = 0;
        $started = time();
        foreach ($shots as $shot) {
            if (time() - $started > self::SLICE_SECONDS) {
                break;
            }
            $done += ShotDub::advance($shot, $episode->dub_vi, $mode) ? 1 : 0;
        }
        $total = max(1, $shots->count());
        Cache::put($stage, "Đang lồng tiếng Việt {$done}/{$total} shot...", now()->addHours(8));
        $episode->update(['vi_render_progress' => 5 + (int) floor($done * 35 / $total)]);
        if ($done < $shots->count()) {
            // Đổi giọng chạy nền ở dịch vụ expressive: hỏi lại sau.
            $this->release($mode === 'character' ? 20 : 1);

            return false;
        }

        return true;
    }

    public function failed(?Throwable $e): void
    {
        $episode = $this->episode->fresh();
        if ($episode && ($episode->{$this->col('render_options')}['run'] ?? null) === $this->run) {
            $episode->update([$this->col('render_status') => 'failed', $this->col('render_error') => mb_substr($e?->getMessage() ?: 'Ghép tập thất bại.', 0, 500)]);
            Cache::forget("episode-render-stage:{$episode->id}");
            if ($episode->score_status === 'composing') {
                $episode->update(['score_status' => 'failed', 'score_error' => 'Ghép tập dừng trước khi sáng tác xong nhạc.']);
            }
            if ($episode->sound_status === 'planning') {
                $episode->update(['sound_status' => 'failed', 'sound_error' => 'Ghép tập dừng trước khi chọn xong âm thanh.']);
            }
        }
        File::deleteDirectory(self::workDir($this->episode, $this->run));
    }
}
