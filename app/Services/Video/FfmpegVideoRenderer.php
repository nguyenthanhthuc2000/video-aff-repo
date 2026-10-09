<?php

namespace App\Services\Video;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Ghép ảnh cảnh thành MP4 dọc: mỗi ảnh zoom chậm (zoompan), chuyển cảnh xfade,
 * chữ + phụ đề vẽ bằng libass, cảnh kết làm mờ ảnh cuối, nhạc nền hoặc âm thanh im lặng.
 */
class FfmpegVideoRenderer implements VideoRenderer
{
    private const TRANSITION_MS = 300;

    public function __construct(
        private readonly string $ffmpeg,
        private readonly string $fontsDir,
        private readonly int $width = 1080,
        private readonly int $height = 1920,
        private readonly int $fps = 30,
        private readonly int $timeout = 600,
    ) {}

    public function render(RenderSpec $spec): void
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

            $result = Process::timeout($this->timeout)->run($this->command($spec, $filterPath));

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
        $cmd = [$this->ffmpeg, '-y', '-hide_banner', '-loglevel', 'error'];

        $last = $spec->scenes[array_key_last($spec->scenes)];
        foreach ([...$spec->scenes, $last] as $scene) {
            $cmd = [...$cmd, '-i', $scene->imagePath];
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
        $t = self::TRANSITION_MS;

        $durations = [...array_map(fn (RenderScene $s) => $s->durationMs, $spec->scenes), $spec->endCardMs];
        $count = count($durations);
        $graph = [];

        foreach ($durations as $i => $ms) {
            // Mỗi clip (trừ clip cuối) dài thêm một đoạn chuyển cảnh để xfade chồng lên.
            $clipMs = $i < $count - 1 ? $ms + $t : $ms;
            $frames = max(1, (int) round($clipMs / 1000 * $this->fps));
            $isEndCard = $i === $count - 1;

            $zoom = match (true) {
                $isEndCard => '1',
                $i % 2 === 0 => "1+0.10*on/{$frames}",
                default => "1.10-0.10*on/{$frames}",
            };

            $chain = "[{$i}:v]scale={$bigW}:{$bigH}:force_original_aspect_ratio=increase,crop={$bigW}:{$bigH},"
                ."zoompan=z='{$zoom}':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d={$frames}:s={$w}x{$h}:fps={$this->fps},"
                .'setsar=1,format=yuv420p';
            if ($isEndCard) {
                $chain .= ',boxblur=30:2,eq=brightness=-0.25';
            }
            $graph[] = $chain."[v{$i}]";
        }

        $prev = 'v0';
        $offsetMs = 0;
        for ($i = 1; $i < $count; $i++) {
            $offsetMs += $durations[$i - 1];
            $out = "x{$i}";
            $graph[] = "[{$prev}][v{$i}]xfade=transition=fade:duration=".$this->seconds($t).':offset='.$this->seconds($offsetMs)."[{$out}]";
            $prev = $out;
        }

        $graph[] = "[{$prev}]ass=filename='".$this->escapeFilterPath($assPath)."':fontsdir='".$this->escapeFilterPath($this->fontsDir)."'[vout]";

        $total = $this->seconds($spec->durationMs());
        $fadeStart = $this->seconds(max(0, $spec->durationMs() - 1500));
        $audioIndex = $count;
        $graph[] = $spec->musicPath
            ? "[{$audioIndex}:a]volume=0.3,atrim=0:{$total},afade=t=out:st={$fadeStart}:d=1.5,asetpts=PTS-STARTPTS[aout]"
            : "[{$audioIndex}:a]anull[aout]";

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
