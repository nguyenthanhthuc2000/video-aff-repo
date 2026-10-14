<?php

namespace App\Services\Film;

use App\Models\Shot;
use App\Services\Audio\ExpressiveTts;
use App\Services\Audio\Tts;
use App\Support\Media;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Lồng tiếng Việt cho một shot: đọc lời dịch bằng Piper (giọng Việt vais1000), mỗi câu đặt vào đúng chỗ câu tiếng Anh trong hình
 * (đọc nhanh lên tối đa MAX_TEMPO lần nếu dài hơn chỗ trống), rồi tuỳ chọn đổi giọng từng câu sang giọng mẫu của nhân vật
 * (Chatterbox, như bản tiếng Anh) để mỗi nhân vật giữ giọng riêng. Miễn phí, chạy trên máy.
 */
final class ShotDub
{
    /**
     * Cách lồng tiếng. plain: giọng Piper chỉnh cao độ / tốc độ theo nhân vật (rõ chữ, nhanh, mỗi nhân vật cố định một kiểu giọng).
     * character: đổi tiếp sang giọng mẫu của nhân vật như bản tiếng Anh (Chatterbox học chủ yếu tiếng Anh nên dễ sai thanh điệu, chậm).
     */
    public const MODES = [
        'plain' => 'Giọng Việt rõ chữ, chỉnh theo nhân vật (nhanh)',
        'character' => 'Giọng giống bản tiếng Anh (thử nghiệm: có thể sai dấu, chậm)',
    ];

    public const MAX_TEMPO = 1.45;

    public const RATE = 24000;

    /** Giọng Piper là giọng nữ: nhân vật giọng nam hạ cao độ để nghe rõ là nam (trước cả khi đổi giọng). */
    public const MALE_PITCH = 0.8;

    /**
     * Từng câu tiếng Việt của shot kèm giọng / cao độ / tốc độ theo nhân vật.
     *
     * @return list<array{text: string, voice: string, pitch: float, speed: float, character_id: ?int, index: int}>
     */
    public static function lines(Shot $shot, array $dub): array
    {
        $texts = $dub['shots'][$shot->id] ?? $dub['shots'][(string) $shot->id] ?? [];

        return collect(ShotAudio::lines($shot, null, 'kokoro'))->map(function ($line, $i) use ($texts) {
            $male = in_array(substr($line['voice'], 0, 2), ['am', 'bm'], true);

            return [
                'text' => (string) ($texts[$i] ?? $texts[(string) $i] ?? ''),
                'voice' => $line['voice'],
                'pitch' => round($line['pitch'] * ($male ? self::MALE_PITCH : 1.0), 3),
                'speed' => $line['speed'],
                'character_id' => $line['character_id'],
                'index' => $i,
            ];
        })->filter(fn ($l) => $l['text'] !== '')->values()->all();
    }

    public static function key(Shot $shot, array $dub, string $mode): ?string
    {
        $lines = self::lines($shot, $dub);

        return $lines === [] ? null : sha1(json_encode([$mode, $lines, $shot->synced_audio_path ?? $shot->audio_path, $shot->duration_ms]));
    }

    public static function isCurrent(Shot $shot, array $dub, string $mode): bool
    {
        $key = self::key($shot, $dub, $mode);

        return $shot->dub_vi_key === $key && ($key === null || $shot->dub_vi_path !== null);
    }

    /**
     * Một bước lồng tiếng; true khi tiếng Việt của shot đã đúng với lời dịch / giọng hiện tại.
     *
     * @throws RuntimeException
     */
    public static function advance(Shot $shot, array $dub, string $mode, ?Tts $tts = null, ?ExpressiveTts $expressive = null): bool
    {
        if (self::isCurrent($shot, $dub, $mode)) {
            return true;
        }
        $lines = self::lines($shot, $dub);
        $key = self::key($shot, $dub, $mode);
        if ($lines === []) {
            self::store($shot, null, null, null);

            return true;
        }
        $taskKey = "shot-dub-vi:{$shot->id}:{$key}";
        if ($mode === 'character' && ($task = Cache::get($taskKey))) {
            $expressive ??= app(ExpressiveTts::class);
            $result = $expressive->poll($task['id']);
            if ($result === null) {
                return false;
            }
            Cache::forget($taskKey);
            if ($result !== false) {
                self::store($shot, $result['contents'], $key, $task['timeline']);

                return true;
            }
            // Dịch vụ khởi động lại, mất yêu cầu: làm lại bên dưới.
        }

        $dir = sys_get_temp_dir().'/dub-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        try {
            $spoken = ($tts ?? app(Tts::class))->speak(
                array_map(fn ($l) => ['lang' => 'vi', 'text' => $l['text'], 'voice' => '', 'pitch' => $l['pitch'], 'speed' => $l['speed']], $lines),
                0, 0, 0,
            );
            File::put("{$dir}/all.wav", $spoken['contents']);
            $durations = array_map(fn ($t) => max(1, $t['end_ms'] - $t['start_ms']), $spoken['lines']);
            $timeline = self::place(self::slots($shot, $lines, $dir), $durations, $shot->duration_ms);

            $inputs = [];
            $filters = [];
            foreach ($timeline as $i => $t) {
                $inputs = [...$inputs, '-i', "{$dir}/all.wav"];
                $from = self::seconds($spoken['lines'][$i]['start_ms']);
                $to = self::seconds($spoken['lines'][$i]['end_ms']);
                $tempo = $t['tempo'] > 1.001 ? ',atempo='.round($t['tempo'], 3) : '';
                $filters[] = "[{$i}:a]atrim={$from}:{$to},asetpts=PTS-STARTPTS{$tempo},atrim=duration=".self::seconds($t['end_ms'] - $t['start_ms'])
                    .',afade=t=out:st='.self::seconds(max(0, $t['end_ms'] - $t['start_ms'] - 40)).":d=0.04,adelay={$t['start_ms']}:all=1[l{$i}]";
            }
            $total = self::seconds($shot->duration_ms);
            $filters[] = implode('', array_map(fn ($i) => "[l{$i}]", array_keys($timeline))).'amix=inputs='.count($timeline)
                .":normalize=0:duration=longest,apad=whole_dur={$total},atrim=duration={$total}[out]";
            $track = "{$dir}/track.wav";
            $result = Process::timeout(120)->run([
                config('videoaff.ffmpeg'), '-v', 'error', '-y', ...$inputs, '-filter_complex', implode(';', $filters), '-map', '[out]',
                '-ac', '1', '-ar', (string) self::RATE, '-c:a', 'pcm_s16le', $track,
            ]);
            if ($result->failed()) {
                throw new RuntimeException('Lồng tiếng Việt lỗi: '.mb_substr(trim($result->errorOutput()), -300));
            }
            $timeline = array_map(fn ($t, $l) => ['start_ms' => $t['start_ms'], 'end_ms' => $t['end_ms'], 'character_id' => $l['character_id']], $timeline, $lines);

            if ($mode !== 'character') {
                self::store($shot, (string) File::get($track), $key, $timeline);

                return true;
            }
            $seriesId = $shot->episode->series_id;
            $refs = [];
            $voices = ShotAudio::lines($shot, null, 'expressive');
            $segments = array_map(function ($t, $i) use ($lines, $voices, $seriesId, &$refs) {
                // Giọng mẫu gốc của nhân vật (cùng file mẫu với bản tiếng Anh).
                $voice = $voices[$lines[$i]['index']] ?? $voices[0];
                $refs[$voice['voice']] ??= (string) Media::disk()->get(VoiceReference::ensure($seriesId, $voice));

                return ['start' => $t['start_ms'] / 1000, 'end' => $t['end_ms'] / 1000, 'ref' => $refs[$voice['voice']]];
            }, $timeline, array_keys($timeline));
            $id = ($expressive ?? app(ExpressiveTts::class))->convert((string) File::get($track), $segments);
            Cache::put($taskKey, ['id' => $id, 'timeline' => $timeline], now()->addHours(8));

            return false;
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * Chỗ của từng câu trong shot (ms): theo đoạn nói trong tiếng đã khớp khẩu hình nếu có, không thì theo mốc tiếng đọc sẵn,
     * không có nữa thì chia đều.
     *
     * @return list<array{start_ms: int, end_ms: int}>
     */
    public static function slots(Shot $shot, array $lines, string $dir): array
    {
        $count = count($lines);
        $even = array_map(fn ($i) => ['start_ms' => (int) ($shot->duration_ms * $i / $count), 'end_ms' => (int) ($shot->duration_ms * ($i + 1) / $count)], range(0, $count - 1));
        $times = $shot->audio_lines ?? [];
        $fallback = count($times) === $count ? array_map(fn ($t) => ['start_ms' => (int) $t['start_ms'], 'end_ms' => (int) $t['end_ms']], $times) : $even;
        if ($shot->synced_audio_path === null) {
            return $fallback;
        }
        $wav = "{$dir}/synced.wav";
        File::put($wav, (string) Media::disk()->get($shot->synced_audio_path));
        $match = VoiceMatch::assign(VoiceMatch::segments($wav), $lines);
        $slots = $fallback;
        $seen = [];
        foreach ($match['segments'] as $s) {
            $i = $s['line'];
            $start = (int) round($s['start'] * 1000);
            $end = (int) round($s['end'] * 1000);
            $slots[$i] = isset($seen[$i]) ? ['start_ms' => min($slots[$i]['start_ms'], $start), 'end_ms' => max($slots[$i]['end_ms'], $end)] : ['start_ms' => $start, 'end_ms' => $end];
            $seen[$i] = true;
        }

        return $slots;
    }

    /**
     * Đặt câu vào chỗ: bắt đầu ở đầu chỗ của câu; dài hơn khoảng trống tới câu sau thì đọc nhanh lên (tối đa MAX_TEMPO),
     * vẫn dài thì lùi lên sớm hơn, cuối cùng cắt ở cuối shot.
     *
     * @param  list<array{start_ms: int, end_ms: int}>  $slots
     * @param  list<int>  $durations  độ dài tiếng Việt từng câu (ms)
     * @return list<array{start_ms: int, end_ms: int, tempo: float}>
     */
    public static function place(array $slots, array $durations, int $shotMs): array
    {
        $result = [];
        $cursor = 0;
        foreach ($durations as $i => $duration) {
            $slot = $slots[$i] ?? end($slots);
            $limit = min($shotMs - 80, isset($slots[$i + 1]) ? $slots[$i + 1]['start_ms'] - 80 : $shotMs - 80);
            $start = max($cursor, $slot['start_ms']);
            $room = max(200, $limit - $start);
            $tempo = $duration > $room ? min(self::MAX_TEMPO, $duration / $room) : 1.0;
            $length = (int) round($duration / $tempo);
            if ($start + $length > $limit) {
                $start = max($cursor, $limit - $length);
            }
            $end = min($shotMs, $start + $length);
            $result[] = ['start_ms' => $start, 'end_ms' => $end, 'tempo' => $tempo];
            $cursor = $end + 60;
        }

        return $result;
    }

    private static function store(Shot $shot, ?string $contents, ?string $key, ?array $timeline): void
    {
        $old = $shot->dub_vi_path;
        $path = null;
        if ($contents !== null) {
            $episode = $shot->episode;
            $path = "series/{$episode->series_id}/episodes/{$episode->id}/shot-{$shot->id}-vi-".substr((string) $key, 0, 8).'.wav';
            Media::disk()->put($path, $contents);
        }
        $shot->update(['dub_vi_path' => $path, 'dub_vi_key' => $key, 'dub_vi_lines' => $timeline]);
        if ($old !== null && $old !== $path) {
            Media::delete($old);
        }
    }

    private static function seconds(int $ms): string
    {
        return rtrim(rtrim(number_format($ms / 1000, 3, '.', ''), '0'), '.') ?: '0';
    }
}
