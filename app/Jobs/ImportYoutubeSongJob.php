<?php

namespace App\Jobs;

use App\Models\AudioTrack;
use App\Services\Audio\YoutubeSong;
use App\Support\Media;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Lấy bài hát từ link YouTube vào thư viện Âm thanh (dịch vụ audio, yt-dlp): file mp3, tên bài, ca sĩ, mô tả, ngôn ngữ.
 * Có phụ đề đủ dùng thì làm lời theo thời gian ngay; không thì tách giọng + ghi lời (faster-whisper theo ngôn ngữ của video).
 * Xong thì Claude phân tích bài hát (thể loại, nhịp, không khí, cấu trúc, chủ đề, lời).
 */
class ImportYoutubeSongJob implements ShouldQueue
{
    use Queueable;

    public const POLL_SECONDS = 10;

    /** Phụ đề có ít nhất bấy nhiêu câu thì dùng làm lời theo thời gian. */
    public const MIN_CAPTIONS = 6;

    public int $timeout = 180;

    public function __construct(public AudioTrack $track) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public function handle(YoutubeSong $youtube): void
    {
        $track = $this->track->fresh();
        if ($track === null || $track->import_status !== 'pending') {
            return;
        }
        $key = "youtube-task:{$track->id}";

        try {
            if (! Cache::has($key)) {
                Cache::put($key, $youtube->start((string) $track->source_url, config('videoaff.audio_max_kb') * 1024), now()->addHour());
                $this->release(self::POLL_SECONDS);

                return;
            }
            $info = $youtube->poll((string) Cache::get($key));
            if ($info === null) {
                $this->release(self::POLL_SECONDS);

                return;
            }
            $contents = $youtube->audio((string) Cache::get($key));
        } catch (RuntimeException $e) {
            Cache::forget($key);
            $this->fail($e);

            return;
        }
        Cache::forget($key);

        $path = "audio/{$track->user_id}/".Str::random(40).'.mp3';
        Media::disk()->put($path, $contents);
        $captions = count($info['captions']) >= self::MIN_CAPTIONS;
        $lyrics = $info['captions_kind'] === 'manual' && $captions ? implode("\n", array_column($info['captions'], 'text')) : null;
        $track->update([
            'name' => Str::limit($info['title'] ?: (string) $track->name, 200, ''),
            'path' => $path,
            'mime' => 'audio/mpeg',
            'size_bytes' => strlen($contents),
            'duration_ms' => $info['duration'] > 0 ? (int) round($info['duration'] * 1000) : null,
            'language' => $info['language'],
            'lyrics' => $track->lyrics ?: $lyrics,
            'import_status' => 'done',
            'import_error' => null,
            'song_info' => [
                ...($track->song_info ?? []),
                'source' => 'youtube',
                'title' => $info['title'],
                'artist' => $info['artist'],
                'channel' => $info['channel'],
                'description' => $info['description'],
                'captions_kind' => $captions ? $info['captions_kind'] : null,
                'analysis_status' => 'waiting',
            ],
            // Phụ đề làm lời theo thời gian luôn, không cần tách giọng.
            ...($captions ? [
                'vocal_status' => 'ready',
                'vocal_error' => null,
                'vocal_analysis' => ['duration' => $info['duration'], 'vocals' => [], 'words' => YoutubeSong::words($info['captions']), 'source' => 'captions'],
            ] : []),
        ]);

        $track = $track->fresh();
        if ($captions) {
            AnalyzeSongJob::dispatch($track);
        } else {
            // Claude phân tích sau khi có lời theo thời gian (AnalyzeVocalsJob gọi tiếp).
            $track->analyzeVocals();
        }
    }

    public function failed(?Throwable $e): void
    {
        Cache::forget("youtube-task:{$this->track->id}");
        $this->track->fresh()?->update(['import_status' => 'failed', 'import_error' => mb_substr($e?->getMessage() ?: 'Lấy bài hát từ YouTube thất bại.', 0, 500)]);
    }
}
