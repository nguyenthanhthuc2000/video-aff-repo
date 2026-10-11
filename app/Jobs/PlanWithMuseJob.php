<?php

namespace App\Jobs;

use App\Enums\VideoProjectStatus;
use App\Models\MuseAnalysis;
use App\Models\VideoProject;
use App\Services\Image\ImageGeneratorException;
use App\Services\Image\MuseBrowserClient;
use App\Services\Image\MuseBrowserImageGenerator;
use App\Services\Image\MuseTask;
use App\Support\Media;
use App\Support\MusePlan;
use App\Support\Voice;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Gửi video mẫu + nhạc nền cho Muse để Muse phân tích và lên phân cảnh (Muse tự nghe / xem, thường mất 5–20 phút),
 * xong thì viết kịch bản theo phân cảnh. Gửi rồi hỏi lại theo mã, không giữ hàng đợi.
 */
class PlanWithMuseJob implements ShouldQueue
{
    use Queueable;

    public const POLL_SECONDS = 20;

    /** Muse phân tích lâu (tự chạy công cụ nghe nhạc / xem video). */
    public const ANSWER_MINUTES = 45;

    public int $timeout = 300;

    public function __construct(public VideoProject $project) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(self::ANSWER_MINUTES + 30);
    }

    public function handle(MuseBrowserClient $client): void
    {
        $project = $this->project->fresh();
        if ($project->muse_plan_status !== 'pending') {
            return;
        }

        try {
            if (! MuseTask::isCode($project->muse_plan_request_id)) {
                $code = MuseTask::code();
                [$attachments, $video, $music, $seconds] = $this->attachments($project);
                $prompt = MusePlan::prompt($project, $video, $music, $seconds);
                MuseBrowserImageGenerator::call(fn () => $client->startTask(
                    $code,
                    'text',
                    self::message($code, $prompt),
                    $attachments,
                    self::ANSWER_MINUTES * 60 * 1000,
                ));
                $project->update(['muse_plan_request_id' => $code]);
                // Lưu kho phân tích (dữ liệu xây bộ skill viết prompt).
                MuseAnalysis::create([
                    'user_id' => $project->user_id,
                    'video_project_id' => $project->id,
                    'kind' => 'plan',
                    'request_code' => $code,
                    'context' => MuseAnalysis::contextFor($project, ['attachments' => array_column($attachments, 'name')]),
                    'prompt' => $prompt,
                ]);
                $this->release(60);

                return;
            }

            $result = MuseBrowserImageGenerator::pollTask($client, $project->muse_plan_request_id, 'text');
            if ($result === null) {
                $this->release(self::POLL_SECONDS);

                return;
            }
            $reply = (string) ($result['text'] ?? '');
            $this->archive($project, ['reply' => $reply]);
            $plan = MusePlan::parse($reply, $project);
        } catch (ImageGeneratorException $e) {
            if ($e->busy) {
                $this->release(30);

                return;
            }
            if ($e->retryable && Cache::increment("muse-retries:plan:{$project->id}") <= 2) {
                $this->archive($project, ['status' => 'failed', 'error' => $e->getMessage(), 'completed_at' => now()]);
                $project->update(['muse_plan_request_id' => null]);
                $this->release(60);

                return;
            }
            $this->fail($e);

            return;
        } catch (RuntimeException $e) {
            $this->fail($e);

            return;
        }

        Cache::forget("muse-retries:plan:{$project->id}");
        $this->archive($project, ['status' => 'ready', 'result' => $plan, 'error' => null, 'completed_at' => now()]);
        $project->update([
            'muse_plan' => $plan,
            'muse_plan_status' => 'ready',
            'muse_plan_error' => null,
            // Đoạn nhạc Muse chọn: các cảnh nối tiếp từ giây này.
            'music_start_ms' => $plan['music_start'] !== null ? (int) round($plan['music_start'] * 1000) : $project->music_start_ms,
        ]);
        self::continueScript($project);
    }

    public function failed(?Throwable $e): void
    {
        $project = $this->project->fresh();
        Cache::forget("muse-retries:plan:{$project->id}");
        $this->archive($project, ['status' => 'failed', 'error' => $e?->getMessage(), 'completed_at' => now()]);
        $project->update([
            'muse_plan_status' => 'failed',
            'muse_plan_error' => mb_substr('Muse chưa lên được phân cảnh: '.($e?->getMessage() ?? 'lỗi không rõ'), 0, 500),
        ]);
        // Không có phân cảnh vẫn viết kịch bản như trước, để dự án không đứng yên.
        self::continueScript($project);
    }

    /** Cập nhật bản lưu kho của lần phân tích đang chạy (theo mã yêu cầu). */
    private function archive(VideoProject $project, array $values): void
    {
        if (MuseTask::isCode($project->muse_plan_request_id)) {
            MuseAnalysis::where('request_code', $project->muse_plan_request_id)->where('kind', 'plan')->get()->each->update($values);
        }
    }

    /** Dự án đang chờ phân cảnh để viết kịch bản thì viết luôn. */
    private static function continueScript(VideoProject $project): void
    {
        if ($project->status === VideoProjectStatus::Scripting) {
            GenerateScriptJob::dispatch($project);
        }
    }

    public static function message(string $code, string $prompt): string
    {
        return "Request code: {$code}\nThis is a new, independent request; judge it on its own, not on earlier requests in this chat.\n\n{$prompt}";
    }

    /**
     * Video mẫu (nguyên bản, có tiếng) và nhạc nền (nén mono cho nhẹ; bỏ qua nếu nhạc chính là tiếng video mẫu).
     *
     * @return array{0: list<array{name: string, base64: string}>, 1: bool, 2: bool, 3: ?float}
     */
    private function attachments(VideoProject $project): array
    {
        $attachments = [];
        $sample = $project->sampleVideo;
        $video = false;
        if ($sample?->path && ($contents = Media::disk()->get($sample->path)) !== null) {
            $attachments[] = ['name' => 'sample-video.'.(pathinfo($sample->path, PATHINFO_EXTENSION) ?: 'mp4'), 'base64' => base64_encode($contents)];
            $video = true;
        }

        $music = false;
        $seconds = $project->audioTrack?->duration_ms ? $project->audioTrack->duration_ms / 1000 : null;
        $path = Voice::musicPath($project);
        if ($path !== null && ! ($video && MusePlan::musicIsSampleAudio($project))) {
            $contents = Media::disk()->get($path) ?? throw new RuntimeException('Không đọc được file nhạc nền.');
            $attachments[] = ['name' => 'background-song.m4a', 'base64' => base64_encode(self::compress($contents, $path))];
            $music = true;
        }

        if ($attachments === []) {
            throw new RuntimeException('Không có video mẫu hay nhạc nền để Muse phân tích.');
        }
        // Ảnh sản phẩm để Muse tả đúng màu, kiểu dáng khi lên phân cảnh.
        $photo = $project->product?->primaryImage?->path;
        if ($photo !== null && ($contents = Media::disk()->get($photo)) !== null) {
            $attachments[] = ['name' => 'product.'.(pathinfo($photo, PATHINFO_EXTENSION) ?: 'jpg'), 'base64' => base64_encode($contents)];
        }

        return [$attachments, $video, $music, $seconds];
    }

    /** Nhạc nén AAC mono 96k (bỏ ảnh bìa) để tải lên Muse nhanh; lỗi thì gửi file gốc. */
    private static function compress(string $contents, string $path): string
    {
        $dir = sys_get_temp_dir().'/muse-plan-'.uniqid();
        mkdir($dir);
        $input = "{$dir}/in.".(pathinfo($path, PATHINFO_EXTENSION) ?: 'mp3');
        $output = "{$dir}/out.m4a";
        try {
            file_put_contents($input, $contents);
            $result = Process::timeout(120)->run([config('videoaff.ffmpeg'), '-loglevel', 'error', '-y', '-i', $input, '-vn', '-ac', '1', '-c:a', 'aac', '-b:a', '96k', $output]);

            return $result->successful() && is_file($output) ? (string) file_get_contents($output) : $contents;
        } finally {
            @unlink($input);
            @unlink($output);
            @rmdir($dir);
        }
    }
}
