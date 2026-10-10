<?php

namespace App\Services\Video;

use App\Models\AudioTrack;
use App\Models\SampleVideo;
use App\Support\Media;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/** "Nhạc như video mẫu": tách tiếng của video mẫu thành một bài trong thư viện Âm thanh (tạo một lần, dùng lại). */
class SampleAudio
{
    public function __construct(private readonly string $ffmpeg, private readonly VideoProbe $probe) {}

    public static function fromConfig(): self
    {
        return new self(config('videoaff.ffmpeg'), VideoProbe::fromConfig());
    }

    /** @throws RuntimeException video không có tiếng / ffmpeg lỗi */
    public function extract(SampleVideo $video): AudioTrack
    {
        if ($video->audioTrack?->isPlayable()) {
            return $video->audioTrack;
        }

        $contents = Media::disk()->get($video->path) ?? throw new RuntimeException('Không đọc được video mẫu.');
        $input = tempnam(sys_get_temp_dir(), 'sample');
        $output = $input.'.m4a';
        file_put_contents($input, $contents);

        try {
            if ($this->probe->hasAudio($input) === false) {
                $video->update(['has_audio' => false]);
                throw new RuntimeException("Video mẫu \"{$video->name}\" không có tiếng.");
            }

            $result = Process::timeout(120)->run([$this->ffmpeg, '-v', 'error', '-y', '-i', $input, '-vn', '-c:a', 'aac', '-b:a', '160k', $output]);
            if ($result->failed() || ! is_file($output) || filesize($output) === 0) {
                throw new RuntimeException('Không tách được tiếng của video mẫu.');
            }

            $path = "audio/{$video->user_id}/sample-{$video->id}.m4a";
            Media::disk()->put($path, file_get_contents($output));

            $track = $video->user->audioTracks()->create([
                'name' => "Nhạc video mẫu: {$video->name}",
                'path' => $path,
                'source_url' => $video->source_url,
                'mime' => 'audio/mp4',
                'size_bytes' => filesize($output),
                'duration_ms' => $video->duration_ms,
                'rights_confirmed_at' => $video->rights_confirmed_at,
            ]);
            $video->update(['audio_track_id' => $track->id, 'has_audio' => true]);

            return $track;
        } finally {
            @unlink($input);
            @unlink($output);
        }
    }
}
