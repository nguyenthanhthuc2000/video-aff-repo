<?php

namespace App\Services\Film;

use App\Models\Shot;
use App\Support\Media;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Ghép video của shot: hình từ clip Muse (16:9, 24 khung/giây, thiếu thì giữ khung cuối), tiếng là lời thoại Kokoro của shot
 * (bỏ tiếng Muse tự tạo để giọng nhân vật luôn đúng giọng đã chọn). Shot không lời thì im lặng.
 */
final class ShotVideo
{
    public const WIDTH = 1920;

    public const HEIGHT = 1080;

    public const FPS = 24;

    /**
     * Tiếng thoại kéo đủ thời lượng shot (AAC), gửi kèm Muse để khớp môi; trả đường dẫn media tạm (gọi xong thì xoá).
     *
     * @throws RuntimeException
     */
    public static function dialogueAttachment(Shot $shot): ?string
    {
        if ($shot->audio_path === null) {
            return null;
        }

        return self::inTemp(function (string $dir) use ($shot) {
            $source = "{$dir}/voice.wav";
            $out = "{$dir}/dialogue.m4a";
            File::put($source, (string) Media::disk()->get($shot->audio_path));
            $seconds = self::seconds(min($shot->duration_ms, ShotClipPrompt::MAX_SECONDS * 1000));
            self::ffmpeg(['-i', $source, '-af', "apad=whole_dur={$seconds}", '-t', $seconds, '-c:a', 'aac', '-b:a', '128k', $out]);
            $path = "tmp/shot-{$shot->id}-dialogue-".bin2hex(random_bytes(3)).'.m4a';
            Media::disk()->put($path, (string) File::get($out));

            return $path;
        });
    }

    /**
     * Ghép clip + lời thoại thành video của shot, trả đường dẫn media.
     *
     * @throws RuntimeException
     */
    public static function mux(Shot $shot): string
    {
        $episode = $shot->episode;

        return self::inTemp(function (string $dir) use ($shot, $episode) {
            $clip = "{$dir}/clip.".(pathinfo((string) $shot->clip_path, PATHINFO_EXTENSION) ?: 'mp4');
            File::put($clip, (string) Media::disk()->get((string) $shot->clip_path));
            $seconds = self::seconds($shot->duration_ms);
            $w = self::WIDTH;
            $h = self::HEIGHT;
            $video = "[0:v]scale={$w}:{$h}:force_original_aspect_ratio=increase,crop={$w}:{$h},fps=".self::FPS.',setsar=1,'
                ."tpad=stop_mode=clone:stop_duration={$seconds},trim=duration={$seconds},setpts=PTS-STARTPTS,format=yuv420p[v]";

            if ($shot->audio_path !== null) {
                $voice = "{$dir}/voice.wav";
                File::put($voice, (string) Media::disk()->get($shot->audio_path));
                $inputs = ['-i', $clip, '-i', $voice];
            } else {
                $inputs = ['-i', $clip, '-f', 'lavfi', '-t', $seconds, '-i', 'anullsrc=r=44100:cl=mono'];
            }
            $audio = "[1:a]aresample=44100,aformat=channel_layouts=stereo,apad=whole_dur={$seconds},atrim=duration={$seconds}[a]";

            $out = "{$dir}/shot.mp4";
            self::ffmpeg([
                ...$inputs,
                '-filter_complex', "{$video};{$audio}", '-map', '[v]', '-map', '[a]',
                '-c:v', 'libx264', '-preset', 'medium', '-crf', '20', '-r', (string) self::FPS,
                '-c:a', 'aac', '-b:a', '160k', '-t', $seconds, '-movflags', '+faststart', $out,
            ]);
            $path = "series/{$episode->series_id}/episodes/{$episode->id}/shot-{$shot->id}-video-".bin2hex(random_bytes(3)).'.mp4';
            Media::disk()->put($path, (string) File::get($out));

            return $path;
        });
    }

    /** @param list<string> $args */
    private static function ffmpeg(array $args): void
    {
        $result = Process::timeout(300)->run([config('videoaff.ffmpeg'), '-v', 'error', '-y', ...$args]);
        if ($result->failed()) {
            throw new RuntimeException('Ghép video shot lỗi: '.mb_substr(trim($result->errorOutput()), -300));
        }
    }

    /**
     * @template T
     *
     * @param  callable(string): T  $callback
     * @return T
     */
    private static function inTemp(callable $callback): mixed
    {
        $dir = sys_get_temp_dir().'/shot-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        try {
            return $callback($dir);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    private static function seconds(int $ms): string
    {
        return rtrim(rtrim(number_format($ms / 1000, 3, '.', ''), '0'), '.');
    }
}
