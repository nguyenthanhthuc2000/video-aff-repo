<?php

namespace App\Services\Film;

use App\Models\Shot;
use App\Services\Audio\ExpressiveTts;
use App\Support\Media;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Khớp khẩu hình: Muse tự đọc lời trong clip (miệng nhân vật khớp tiếng đó, không khớp tiếng mình gửi). Lấy tiếng Muse làm chuẩn nhịp,
 * tách các đoạn nói, gán mỗi đoạn cho người nói của câu tương ứng, rồi đổi giọng từng đoạn sang giọng mẫu cố định của nhân vật
 * (Chatterbox voice conversion) và đặt lại đúng chỗ. Kết quả: miệng khớp, giọng vẫn là giọng riêng của nhân vật ở mọi shot.
 */
final class VoiceMatch
{
    /** Ngưỡng im lặng / độ dài tối thiểu của khoảng lặng để tách câu. */
    public const SILENCE_DB = -35;

    public const SILENCE_SECONDS = 0.25;

    /**
     * Đoạn nói trong file tiếng (giây), theo khoảng lặng.
     *
     * @return list<array{start: float, end: float}>
     */
    public static function segments(string $wav): array
    {
        $result = Process::timeout(60)->run([
            config('videoaff.ffmpeg'), '-hide_banner', '-i', $wav, '-af', 'silencedetect=n='.self::SILENCE_DB.'dB:d='.self::SILENCE_SECONDS, '-f', 'null', '-',
        ]);
        $log = $result->errorOutput();
        preg_match('/Duration: (\d+):(\d+):([\d.]+)/', $log, $d);
        $duration = $d ? $d[1] * 3600 + $d[2] * 60 + (float) $d[3] : 0.0;
        preg_match_all('/silence_(start|end): ([\d.]+)/', $log, $m, PREG_SET_ORDER);

        $segments = [];
        $cursor = 0.0;
        $silent = false;
        foreach ($m as [, $kind, $time]) {
            $time = (float) $time;
            if ($kind === 'start') {
                $time > $cursor + 0.12 && $segments[] = ['start' => round($cursor, 3), 'end' => round($time, 3)];
                $silent = true;
            } else {
                $cursor = $time;
                $silent = false;
            }
        }
        if (! $silent && $duration > $cursor + 0.12) {
            $segments[] = ['start' => round($cursor, 3), 'end' => round($duration, 3)];
        }

        return $segments;
    }

    /**
     * Gán đoạn nói cho câu thoại: đủ số đoạn thì theo thứ tự; dư đoạn thì gộp các đoạn gần nhau nhất; thiếu đoạn (Muse đọc liền
     * nhiều câu) thì mỗi đoạn lấy người nói của câu ở cùng vị trí tương đối, kèm cảnh báo.
     *
     * @param  list<array{start: float, end: float}>  $segments
     * @param  list<array{character_id: ?int}>  $lines
     * @return array{segments: list<array{start: float, end: float, line: int}>, warning: ?string}
     */
    public static function assign(array $segments, array $lines): array
    {
        $count = count($lines);
        if ($count === 0 || $segments === []) {
            return ['segments' => [], 'warning' => $count > 0 ? 'Không nghe thấy lời nói trong clip Muse: dùng tiếng đọc sẵn (miệng có thể không khớp). Bấm "Làm lại video".' : null];
        }
        while (count($segments) > $count) {
            $best = 0;
            for ($i = 1; $i < count($segments) - 1; $i++) {
                if ($segments[$i + 1]['start'] - $segments[$i]['end'] < $segments[$best + 1]['start'] - $segments[$best]['end']) {
                    $best = $i;
                }
            }
            array_splice($segments, $best, 2, [['start' => $segments[$best]['start'], 'end' => $segments[$best + 1]['end']]]);
        }
        if (count($segments) === $count) {
            return ['segments' => array_map(fn ($s, $i) => [...$s, 'line' => $i], $segments, array_keys($segments)), 'warning' => null];
        }

        $span = [$segments[0]['start'], end($segments)['end']];
        $length = max(0.01, $span[1] - $span[0]);
        $assigned = array_map(function ($s) use ($span, $length, $count) {
            $middle = (($s['start'] + $s['end']) / 2 - $span[0]) / $length;

            return [...$s, 'line' => min($count - 1, (int) floor($middle * $count))];
        }, $segments);
        $speakers = array_unique(array_map(fn ($l) => $l['character_id'] ?? 0, $lines));

        return ['segments' => $assigned, 'warning' => count($speakers) > 1 ? 'Muse đọc liền nhiều câu: giọng có thể gán chưa đúng người nói ở chỗ chuyển câu.' : null];
    }

    /**
     * Một bước khớp tiếng cho clip của shot; true khi xong (đã có synced_audio_path hoặc dùng tiếng đọc sẵn).
     *
     * @throws RuntimeException
     */
    public static function advance(Shot $shot, ?ExpressiveTts $expressive = null): bool
    {
        // Giọng gốc của nhân vật (không nhân hệ số cảm xúc của Kokoro) để giọng mẫu luôn là một, giống nhau ở mọi câu, mọi shot.
        $lines = ShotAudio::lines($shot, null, 'expressive');
        if ($lines === []) {
            self::finish($shot, null, null);

            return true;
        }
        $expressive ??= app(ExpressiveTts::class);
        $taskKey = "shot-voice-match:{$shot->id}:".md5((string) $shot->clip_path.(string) ShotAudio::key($shot));
        if ($task = Cache::get($taskKey)) {
            $result = $expressive->poll($task);
            if ($result === null) {
                return false;
            }
            Cache::forget($taskKey);
            if ($result !== false) {
                $episode = $shot->episode;
                $path = "series/{$episode->series_id}/episodes/{$episode->id}/shot-{$shot->id}-synced-".bin2hex(random_bytes(3)).'.wav';
                Media::disk()->put($path, $result['contents']);
                self::finish($shot, $path, Cache::pull("{$taskKey}:warning"));

                return true;
            }
        }

        $dir = sys_get_temp_dir().'/voice-match-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        try {
            $clip = "{$dir}/clip.mp4";
            $wav = "{$dir}/muse.wav";
            File::put($clip, (string) Media::disk()->get((string) $shot->clip_path));
            $extract = Process::timeout(60)->run([config('videoaff.ffmpeg'), '-v', 'error', '-y', '-i', $clip, '-vn', '-ac', '1', '-ar', '24000', $wav]);
            $match = $extract->successful() && is_file($wav) ? self::assign(self::segments($wav), $lines) : ['segments' => [], 'warning' => 'Clip Muse không có tiếng: dùng tiếng đọc sẵn (miệng có thể không khớp).'];
            if ($match['segments'] === []) {
                self::finish($shot, null, $match['warning']);

                return true;
            }
            $seriesId = $shot->episode->series_id;
            $refs = [];
            $segments = array_map(function ($s) use ($lines, $seriesId, &$refs) {
                $voice = $lines[$s['line']];
                $refs[$voice['voice']] ??= (string) Media::disk()->get(VoiceReference::ensure($seriesId, $voice));

                return ['start' => $s['start'], 'end' => $s['end'], 'ref' => $refs[$voice['voice']]];
            }, $match['segments']);
            Cache::put($taskKey, $expressive->convert((string) File::get($wav), $segments), now()->addHours(8));
            if ($match['warning']) {
                Cache::put("{$taskKey}:warning", $match['warning'], now()->addHours(8));
            }

            return false;
        } finally {
            File::deleteDirectory($dir);
        }
    }

    private static function finish(Shot $shot, ?string $path, ?string $warning): void
    {
        $old = $shot->synced_audio_path;
        $shot->update(['synced_audio_path' => $path, 'sync_warning' => $warning]);
        if ($old !== null && $old !== $path) {
            Media::delete($old);
        }
    }
}
