<?php

namespace App\Services\Video;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Ghép ảnh cảnh thành MP4 dọc: mỗi ảnh zoom chậm (zoompan) hoặc dùng clip chuyển động nếu có, chuyển cảnh xfade,
 * chữ + phụ đề vẽ bằng libass, cảnh kết làm mờ ảnh cuối, nhạc nền hoặc âm thanh im lặng.
 */
class FfmpegVideoRenderer implements VideoRenderer
{
    public function __construct(
        private readonly string $ffmpeg,
        private readonly string $fontsDir,
        private readonly int $width = 1080,
        private readonly int $height = 1920,
        private readonly int $fps = 30,
        private readonly int $timeout = 600,
    ) {}

    public function render(RenderSpec $spec, ?callable $onProgress = null): void
    {
        if ($spec->scenes === []) {
            throw new VideoRenderException('Không có cảnh nào để ghép.');
        }

        $workDir = dirname($spec->outputPath).'/work-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workDir);

        try {
            $assPath = $workDir.'/subs.ass';
            File::put($assPath, AssSubtitles::build($spec, $this->width, $this->height));

            $filterPath = $workDir.'/filter.txt';
            File::put($filterPath, $this->filterGraph($spec, $assPath));

            // -progress in "out_time_us=..." ra stdout; quy ra phần trăm theo tổng thời lượng.
            $totalUs = max(1, $spec->durationMs() * 1000);
            $result = Process::timeout($this->timeout)->run(
                $this->command($spec, $filterPath),
                function (string $type, string $output) use ($onProgress, $totalUs) {
                    if ($onProgress && $type === 'out' && preg_match_all('/out_time_us=(\d+)/', $output, $m)) {
                        $onProgress(min(100, (int) floor(((int) end($m[1])) * 100 / $totalUs)));
                    }
                },
            );

            if ($result->failed() || ! is_file($spec->outputPath)) {
                throw new VideoRenderException('ffmpeg lỗi: '.mb_substr(trim($result->errorOutput()), -800));
            }
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    /** @return list<string> */
    public function command(RenderSpec $spec, string $filterPath): array
    {
        $cmd = [$this->ffmpeg, '-y', '-hide_banner', '-loglevel', 'error', '-nostats', '-progress', 'pipe:1'];

        foreach ($spec->scenes as $scene) {
            // Lặp clip nếu ngắn hơn thời lượng cảnh; tiếng của clip chỉ giữ khi người mẫu nói (clipAudio).
            $cmd = $scene->clipPath
                ? [...$cmd, '-stream_loop', '-1', '-i', $scene->clipPath]
                : [...$cmd, '-i', $scene->imagePath];
        }
        // Cảnh kết dùng ảnh tĩnh của cảnh cuối.
        if ($spec->hasEndCard()) {
            $cmd = [...$cmd, '-i', $spec->scenes[array_key_last($spec->scenes)]->imagePath];
        }

        $seconds = $this->seconds($spec->durationMs());
        if ($spec->musicPath) {
            $cmd = [...$cmd, '-stream_loop', '-1', '-i', $spec->musicPath];
        } else {
            $cmd = [...$cmd, '-f', 'lavfi', '-t', $seconds, '-i', 'anullsrc=r=44100:cl=stereo'];
        }

        return [
            ...$cmd,
            '-filter_complex_script', $filterPath,
            '-map', '[vout]', '-map', '[aout]',
            '-c:v', 'libx264', '-preset', 'medium', '-crf', '20', '-pix_fmt', 'yuv420p', '-r', (string) $this->fps,
            '-c:a', 'aac', '-b:a', '128k',
            '-t', $seconds,
            '-movflags', '+faststart',
            $spec->outputPath,
        ];
    }

    public function filterGraph(RenderSpec $spec, string $assPath): string
    {
        $w = $this->width;
        $h = $this->height;
        // Phóng ảnh 1,5 lần trước khi zoompan để chuyển động mượt hơn.
        $bigW = (int) round($w * 1.5);
        $bigH = (int) round($h * 1.5);
        $t = $spec->transitionMs;

        $durations = array_map(fn (RenderScene $s) => $s->durationMs, $spec->scenes);
        if ($spec->hasEndCard()) {
            $durations[] = $spec->endCardMs;
        }
        $count = count($durations);
        $graph = [];

        foreach ($durations as $i => $ms) {
            // Mỗi clip (trừ clip cuối) dài thêm một đoạn chuyển cảnh để xfade chồng lên (cắt thẳng thì không).
            $clipMs = $t > 0 && $i < $count - 1 ? $ms + $t : $ms;
            $frames = max(1, (int) round($clipMs / 1000 * $this->fps));
            $isEndCard = $spec->hasEndCard() && $i === $count - 1;

            $zoom = match (true) {
                $isEndCard => '1',
                // Zoom rất nhẹ: zoom mạnh kiểu Ken Burns trông như trình chiếu ảnh.
                $i % 2 === 0 => "1+0.04*on/{$frames}",
                default => "1.04-0.04*on/{$frames}",
            };

            $clip = $isEndCard ? null : $spec->scenes[$i]->clipPath;
            // Clip: cắt khung dọc, lấy đúng thời lượng; fps đặt sau trim để xfade nhận frame rate cố định.
            $chain = $clip
                ? "[{$i}:v]scale={$w}:{$h}:force_original_aspect_ratio=increase,crop={$w}:{$h},"
                    .'trim='.($spec->scenes[$i]->clipOffsetMs > 0 ? 'start='.$this->seconds($spec->scenes[$i]->clipOffsetMs).':' : '')
                    .'duration='.$this->seconds($clipMs).",setpts=PTS-STARTPTS,fps={$this->fps},setsar=1,format=yuv420p"
                : "[{$i}:v]scale={$bigW}:{$bigH}:force_original_aspect_ratio=increase,crop={$bigW}:{$bigH},"
                    ."zoompan=z='{$zoom}':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d={$frames}:s={$w}x{$h}:fps={$this->fps},"
                    .'setsar=1,format=yuv420p';
            if ($isEndCard) {
                $chain .= ',boxblur=30:2,eq=brightness=-0.25';
            }
            $graph[] = $chain."[v{$i}]";
        }

        $prev = 'v0';
        if ($t > 0) {
            $offsetMs = 0;
            for ($i = 1; $i < $count; $i++) {
                $offsetMs += $durations[$i - 1];
                $out = "x{$i}";
                $graph[] = "[{$prev}][v{$i}]xfade=transition=fade:duration=".$this->seconds($t).':offset='.$this->seconds($offsetMs)."[{$out}]";
                $prev = $out;
            }
        } elseif ($count > 1) {
            // Cắt thẳng: nối các cảnh liền nhau.
            $graph[] = implode('', array_map(fn (int $i) => "[v{$i}]", range(0, $count - 1)))."concat=n={$count}:v=1:a=0[vcat]";
            $prev = 'vcat';
        }
        if ($spec->grain) {
            $graph[] = "[{$prev}]noise=alls=5:allf=t,eq=saturation=0.96[vgrain]";
            $prev = 'vgrain';
        }

        $graph[] = $spec->withText
            ? "[{$prev}]ass=filename='".$this->escapeFilterPath($assPath)."':fontsdir='".$this->escapeFilterPath($this->fontsDir)."'[vout]"
            : "[{$prev}]null[vout]";

        $total = $this->seconds($spec->durationMs());
        $fadeStart = $this->seconds(max(0, $spec->durationMs() - 1500));
        $audioIndex = $count;
        $start = $this->seconds($spec->musicStartMs);
        $end = $this->seconds($spec->musicStartMs + $spec->durationMs());
        $music = $spec->musicPath
            ? "[{$audioIndex}:a]atrim={$start}:{$end},asetpts=PTS-STARTPTS,volume=".$spec->musicVolume().",afade=t=out:st={$fadeStart}:d=1.5"
            : "[{$audioIndex}:a]anull";

        if (! $spec->clipAudio) {
            $graph[] = "{$music}[aout]";

            return implode(";\n", $graph);
        }

        // Người mẫu nói: nối tiếng từng clip đúng thời lượng cảnh (cảnh ảnh tĩnh / cảnh kết là im lặng) rồi trộn nhạc nền nhỏ.
        foreach ($durations as $i => $ms) {
            $seconds = $this->seconds($ms);
            $scene = $spec->scenes[$i] ?? null;
            $graph[] = $scene?->clipPath && $scene->clipHasAudio
                ? "[{$i}:a]aresample=44100,aformat=channel_layouts=stereo,atrim=0:{$seconds},asetpts=PTS-STARTPTS,apad=whole_dur={$seconds}[a{$i}]"
                : "anullsrc=r=44100:cl=stereo,atrim=0:{$seconds}[a{$i}]";
        }
        $graph[] = implode('', array_map(fn (int $i) => "[a{$i}]", range(0, $count - 1)))."concat=n={$count}:v=0:a=1[voice]";
        $graph[] = "{$music},aresample=44100,aformat=channel_layouts=stereo[music]";
        $graph[] = '[voice][music]amix=inputs=2:duration=first:normalize=0[aout]';

        return implode(";\n", $graph);
    }

    private function seconds(int $ms): string
    {
        return rtrim(rtrim(number_format($ms / 1000, 3, '.', ''), '0'), '.');
    }

    private function escapeFilterPath(string $path): string
    {
        return str_replace(['\\', "'", ':'], ['\\\\', "\\'", '\\:'], $path);
    }
}
