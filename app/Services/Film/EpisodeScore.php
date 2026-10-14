<?php

namespace App\Services\Film;

use App\Models\Episode;
use App\Models\Shot;
use App\Services\Audio\MusicSynth;
use App\Support\Media;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Nhạc riêng của tập (ScoreComposer) thành tiếng: bản nghe thử từng đoạn, và cả dải nhạc của tập khi ghép
 * (nhạc hiệu ở thẻ tiêu đề, mỗi phân đoạn một đoạn nhạc dài đúng các shot của phân đoạn, đoạn kết ở thẻ kết).
 */
final class EpisodeScore
{
    public const PREVIEW_SECONDS = 12;

    /** Nghe thử từng đoạn; trả score kèm 'previews' (đường dẫn media). @throws RuntimeException */
    public static function withPreviews(Episode $episode, array $score, ?MusicSynth $synth = null): array
    {
        $cues = [['cue' => $score['title'], 'seconds' => self::PREVIEW_SECONDS], ['cue' => $score['end'], 'seconds' => self::PREVIEW_SECONDS],
            ...array_map(fn ($s) => ['cue' => $s['cue'], 'seconds' => self::PREVIEW_SECONDS], $score['sequences'])];
        $files = ($synth ?? app(MusicSynth::class))->render($cues);
        $dir = "series/{$episode->series_id}/episodes/{$episode->id}/score-".bin2hex(random_bytes(3));
        $paths = [];
        foreach ($files as $i => $contents) {
            Media::disk()->put($paths[] = "{$dir}/cue-{$i}.wav", $contents);
        }
        self::forget($episode->score);

        return [...$score, 'previews' => ['title' => $paths[0] ?? null, 'end' => $paths[1] ?? null, 'sequences' => array_slice($paths, 2)]];
    }

    /** Xoá file nghe thử của bản nhạc cũ. */
    public static function forget(?array $score): void
    {
        foreach ([$score['previews']['title'] ?? null, $score['previews']['end'] ?? null, ...($score['previews']['sequences'] ?? [])] as $path) {
            Media::delete($path);
        }
    }

    /**
     * Dải nhạc cả tập theo kế hoạch ghép (WAV trong $dir).
     *
     * @throws RuntimeException
     */
    public static function track(Episode $episode, array $plan, string $dir, ?MusicSynth $synth = null): ?string
    {
        $score = $episode->score;
        if (empty($score['title'])) {
            return null;
        }
        $sequences = $episode->shots()->get(['id', 'sequence'])->pluck('sequence', 'id');
        $cueFor = collect($score['sequences'] ?? [])->keyBy('sequence');
        // Gom các đoạn liền nhau cùng một đoạn nhạc.
        $spans = [];
        foreach ($plan as $segment) {
            $key = match ($segment['type']) {
                'title' => 'title',
                'end' => 'end',
                default => 'seq:'.($sequences[$segment['shot_id']] ?? 0),
            };
            if ($spans !== [] && end($spans)['key'] === $key) {
                $spans[array_key_last($spans)]['ms'] += $segment['duration_ms'];
            } else {
                $spans[] = ['key' => $key, 'ms' => $segment['duration_ms']];
            }
        }
        $cues = array_map(fn ($span) => [
            'cue' => match (true) {
                $span['key'] === 'title' => $score['title'],
                $span['key'] === 'end' => $score['end'],
                default => $cueFor[(int) substr($span['key'], 4)]['cue'] ?? $score['title'],
            },
            'seconds' => round($span['ms'] / 1000, 3),
        ], $spans);
        $files = ($synth ?? app(MusicSynth::class))->render($cues);
        $inputs = [];
        foreach ($files as $i => $contents) {
            File::put($file = "{$dir}/score-{$i}.wav", $contents);
            $inputs = [...$inputs, '-i', $file];
        }
        $out = "{$dir}/score.wav";
        $labels = implode('', array_map(fn ($i) => "[{$i}:a]", array_keys($files)));
        $result = Process::timeout(300)->run([
            config('videoaff.ffmpeg'), '-v', 'error', '-y', ...$inputs,
            '-filter_complex', "{$labels}concat=n=".count($files).':v=0:a=1,aresample=44100,aformat=channel_layouts=stereo[out]', '-map', '[out]', $out,
        ]);
        if ($result->failed()) {
            throw new RuntimeException('Ghép nhạc của tập lỗi: '.mb_substr(trim($result->errorOutput()), -300));
        }

        return $out;
    }
}
