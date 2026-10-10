<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeSampleVideoJob;
use App\Models\SampleVideo;
use App\Services\Video\SampleAudio;
use App\Services\Video\VideoProbe;
use App\Support\Media;
use App\Support\ScriptDriver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/** Quản lý video mẫu (dùng để sao chép chuyển động sang người mẫu). */
class SampleVideoController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('sample-videos/index', [
            'sampleVideos' => $request->user()->sampleVideos()->latest()->get()->map(fn (SampleVideo $video) => self::present($video)),
            'maxMb' => (int) (config('videoaff.sample_video_max_kb') / 1024),
            'recommendedSeconds' => config('videoaff.sample_video_recommended_seconds'),
        ]);
    }

    public function store(Request $request, VideoProbe $probe): RedirectResponse
    {
        $validated = $request->validate([
            'video' => ['required', 'file', 'mimetypes:video/mp4,video/quicktime,video/webm', 'max:'.config('videoaff.sample_video_max_kb')],
            'name' => ['nullable', 'string', 'max:255'],
            'source_url' => ['nullable', 'url', 'max:2048'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'rights' => ['accepted'],
        ], [
            'rights.accepted' => 'Cần xác nhận bạn có quyền sử dụng video này.',
            'video.mimetypes' => 'Chỉ nhận video MP4, MOV hoặc WEBM.',
        ], ['video' => 'video', 'name' => 'tên', 'source_url' => 'link nguồn', 'notes' => 'ghi chú']);

        $file = $request->file('video');
        $directory = "sample-videos/{$request->user()->id}";
        $path = Media::store($file, $directory);

        $video = $request->user()->sampleVideos()->create([
            'name' => ($validated['name'] ?? null) ?: Str::limit(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 250, ''),
            'path' => $path,
            'mime' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'source_url' => $validated['source_url'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'rights_confirmed_at' => now(),
        ]);

        $this->analyze($video, $probe, $file->getRealPath());
        $video->update(['has_audio' => $probe->hasAudio($file->getRealPath())]);
        $queued = self::queueMotionAnalysis($video);

        return back()->with('status', "Đã thêm video mẫu \"{$video->name}\".".($queued ? ' AI đang phân tích chuyển động...' : ''));
    }

    /** Phân tích lại chuyển động (Claude xem khung hình). */
    public function reanalyze(SampleVideo $sampleVideo): RedirectResponse
    {
        $this->authorize('update', $sampleVideo);

        return back()->with('status', self::queueMotionAnalysis($sampleVideo)
            ? 'AI đang phân tích chuyển động...'
            : 'Phân tích chuyển động chưa sẵn sàng trên hệ thống. Liên hệ quản trị viên.');
    }

    /** "Nhạc như video mẫu": tách tiếng của video thành một bài trong thư viện Âm thanh. */
    public function extractAudio(SampleVideo $sampleVideo, SampleAudio $audio): RedirectResponse
    {
        $this->authorize('update', $sampleVideo);

        try {
            $track = $audio->extract($sampleVideo);
        } catch (RuntimeException $e) {
            return back()->withErrors(['sample_video' => $e->getMessage()]);
        }

        return back()->with('status', "Đã thêm \"{$track->name}\" vào thư viện Âm thanh.");
    }

    /** Đưa việc phân tích chuyển động vào hàng đợi; chế độ Claude bản web (không có Claude chạy ngầm) thì bỏ qua. */
    private static function queueMotionAnalysis(SampleVideo $video): bool
    {
        if (ScriptDriver::current() === 'web') {
            return false;
        }

        $video->update(['analysis_status' => 'pending', 'analysis_error' => null]);
        AnalyzeSampleVideoJob::dispatch($video);

        return true;
    }

    public function update(Request $request, SampleVideo $sampleVideo): RedirectResponse
    {
        $this->authorize('update', $sampleVideo);

        $sampleVideo->update($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'source_url' => ['nullable', 'url', 'max:2048'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['name' => 'tên', 'source_url' => 'link nguồn', 'notes' => 'ghi chú']));

        return back()->with('status', 'Đã lưu video mẫu.');
    }

    public function destroy(SampleVideo $sampleVideo): RedirectResponse
    {
        $this->authorize('delete', $sampleVideo);

        $sampleVideo->delete();
        Media::delete($sampleVideo->path);
        Media::delete($sampleVideo->thumbnail_path);

        return back()->with('status', "Đã xoá video mẫu \"{$sampleVideo->name}\".");
    }

    /** Thời lượng, độ phân giải và ảnh bìa; đọc từ file tải lên (còn trên máy) nên không phụ thuộc loại disk. */
    private function analyze(SampleVideo $video, VideoProbe $probe, string $localPath): void
    {
        $info = $probe->probe($localPath);
        if ($info === null) {
            return;
        }

        $thumbnail = tempnam(sys_get_temp_dir(), 'thumb').'.jpg';
        $at = $info['duration_ms'] ? min(1.0, $info['duration_ms'] / 2000) : 0.0;
        $thumbnailPath = null;
        if ($probe->thumbnail($localPath, $thumbnail, $at)) {
            $thumbnailPath = "sample-videos/{$video->user_id}/thumb-{$video->id}-".Str::random(8).'.jpg';
            Media::disk()->put($thumbnailPath, file_get_contents($thumbnail));
        }
        @unlink($thumbnail);

        $video->update([...$info, 'thumbnail_path' => $thumbnailPath]);
    }

    /** @return array<string, mixed> */
    public static function present(SampleVideo $video): array
    {
        return [
            'id' => $video->id,
            'name' => $video->name,
            'url' => Media::url($video->path),
            'thumbnail_url' => Media::url($video->thumbnail_path),
            'mime' => $video->mime,
            'size_bytes' => $video->size_bytes,
            'duration_ms' => $video->duration_ms,
            'width' => $video->width,
            'height' => $video->height,
            'source_url' => $video->source_url,
            'notes' => $video->notes,
            'analysis_status' => $video->analysis_status,
            'analysis' => $video->motion_analysis,
            'shots' => $video->shots ?? [],
            'analysis_error' => $video->analysis_error,
            'has_audio' => $video->has_audio,
            'audio_track_id' => $video->audio_track_id,
            'created_at' => $video->created_at,
        ];
    }
}
