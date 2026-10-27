<?php

namespace App\Services\TopicShort;

use App\Models\AudioTrack;
use App\Models\MangaPanel;
use App\Models\TopicShort;
use App\Models\TopicShortScene;
use App\Services\Audio\Tts;
use App\Services\Film\EpisodeRenderer;
use App\Services\Film\Voices;
use App\Services\Video\AssSubtitles;
use App\Support\BuiltinMusic;
use App\Support\Media;
use App\Support\Pronounce;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Dựng short 9:16: giọng AI đọc lời kể (Kokoro tiếng Anh / Piper tiếng Việt), mỗi cảnh một ảnh
 * (ảnh ngang đặt giữa trên nền mờ của chính nó, zoom chậm), chữ lớn phía trên, phụ đề theo câu phía dưới, nhạc nền tuỳ chọn.
 */
class ShortVideo
{
    public const WIDTH = 1080;

    public const HEIGHT = 1920;

    public const FPS = 30;

    /**
     * Hiệu ứng âm thanh tạo ngay bằng nguồn lavfi của ffmpeg (không cần file, không bản quyền).
     * impact: cú đấm / đá / bị đánh; whoosh: lao tới; energy: gom khí, tuyệt chiêu; pop: ngạc nhiên.
     */
    public const SFX = [
        'impact' => 'anoisesrc=d=0.45:c=brown:a=1,lowpass=f=260,volume=4,afade=t=out:st=0.04:d=0.4',
        'whoosh' => 'anoisesrc=d=0.6:c=pink:a=0.8,bandpass=f=1400:width_type=h:w=1600,afade=t=in:d=0.25,afade=t=out:st=0.3:d=0.3',
        'energy' => 'sine=f=90:d=1.2,tremolo=f=14:d=0.7,volume=2.5,afade=t=in:d=0.4,afade=t=out:st=0.8:d=0.4',
        'pop' => 'sine=f=1200:d=0.14,afade=t=out:st=0.02:d=0.12',
    ];

    /** Mã tư thế khung manga → hiệu ứng âm thanh. */
    private const POSE_SFX = ['A1' => 'impact', 'A2' => 'impact', 'A6' => 'impact', 'A4' => 'whoosh', 'A5' => 'energy', 'E4' => 'pop'];

    /** Từ khoá tên bài nhạc cho từng cảm xúc (gợi ý nhạc nền). */
    public const MOOD_MUSIC = [
        'tense' => ['tense', 'dark', 'suspense', 'căng', 'hồi hộp', 'kịch tính'],
        'sad' => ['sad', 'piano', 'emotional', 'buồn', 'cảm động'],
        'epic' => ['epic', 'battle', 'action', 'fight', 'hùng', 'chiến', 'trận'],
        'funny' => ['funny', 'comedy', 'happy', 'vui', 'hài'],
        'calm' => ['calm', 'chill', 'lofi', 'ambient', 'nhẹ', 'êm'],
    ];

    /** Số từ tối đa mỗi dòng phụ đề. */
    private const CHUNK_WORDS = 7;

    /**
     * @param  array{music_track_id?: ?int, music_file?: ?string, music_volume?: ?int, sfx?: bool}  $options
     * @return array{path: string, ms: int}
     *
     * @throws RuntimeException
     */
    public static function render(TopicShort $short, string $dir, array $options = [], ?Tts $tts = null): array
    {
        $scenes = $short->scenes()->get();
        if ($scenes->isEmpty() || $scenes->contains(fn ($scene) => $scene->image_status !== 'ready' || ! $scene->image_path)) {
            throw new RuntimeException('Mọi cảnh phải có ảnh trước khi dựng video.');
        }

        $voice = self::voice($short, $scenes->values()->all(), $dir, $tts ?? app(Tts::class));
        $total = $voice['duration_ms'];
        $lines = $voice['lines'];

        // Ranh giới cảnh: đổi ảnh ngay trước câu tiếp theo.
        $bounds = [0];
        for ($i = 1; $i < $scenes->count(); $i++) {
            $bounds[] = max($bounds[$i - 1] + 500, (int) ($lines[$i]['start_ms'] ?? $bounds[$i - 1] + 3000) - 150);
        }
        $bounds[] = max($total, end($bounds) + 500);
        $total = end($bounds);

        $fast = ($options['style'] ?? 'fast') === 'fast';
        $args = [];
        $filters = [];
        $events = [];
        $count = 0;
        foreach ($scenes->values() as $i => $scene) {
            // Cảnh có video ngắn Muse làm: dùng video (lặp nếu ngắn hơn lời kể) thay ảnh động.
            $clip = self::clip($scene, $dir, $i);
            if ($fast) {
                // Kiểu cắt nhanh: mỗi khung manga thật là một cảnh quay riêng 2–4 giây, phủ kín màn hình, zoom / lia liên tục.
                foreach ($clip ? [[$clip, $bounds[$i], $bounds[$i + 1]]] : self::shots($scene, $dir, $i, $bounds[$i], $bounds[$i + 1]) as [$local, $from, $to]) {
                    $frames = max(1, (int) round(($to - $from) / 1000 * self::FPS));
                    [$filter, $input] = $clip ? self::clipFilter($count, $local, $frames) : self::shotFilter($count, $local, $frames);
                    $args = [...$args, ...$input];
                    $filters[] = $filter;
                    $count++;
                }
                $start = (int) ($lines[$i]['start_ms'] ?? $bounds[$i]);
                $end = (int) ($lines[$i]['end_ms'] ?? $bounds[$i + 1]);
                foreach (self::words($scene->narration, $start, $end) as $word) {
                    $events[] = [$word[0], $word[1], 'Word', $word[2]];
                }

                continue;
            }
            $frames = max(1, (int) round(($bounds[$i + 1] - $bounds[$i]) / 1000 * self::FPS));
            if ($clip) {
                [$filter, $input] = self::clipFilter($count, $clip, $frames);
                $args = [...$args, ...$input];
                $filters[] = $filter;
            } else {
                $local = "{$dir}/img{$i}.".(pathinfo($scene->image_path, PATHINFO_EXTENSION) ?: 'jpg');
                $contents = Media::disk()->get($scene->image_path);
                if ($contents === null) {
                    throw new RuntimeException("Không đọc được ảnh cảnh {$scene->position}.");
                }
                File::put($local, $contents);
                $args = [...$args, '-i', $local];
                $filters[] = self::sceneFilter($count, $frames, $scene->image_source === 'page');
            }
            $count++;

            $title = $i === 0 && $short->title ? $short->title : (string) $scene->on_screen_text;
            $title !== '' && $events[] = [$bounds[$i], $bounds[$i + 1], $i === 0 ? 'Title' : 'Top', $title];
            $start = (int) ($lines[$i]['start_ms'] ?? $bounds[$i]);
            $end = (int) ($lines[$i]['end_ms'] ?? $bounds[$i + 1]);
            foreach (self::chunks($scene->narration, $start, $end) as $chunk) {
                $events[] = [$chunk[0], $chunk[1], 'Sub', $chunk[2]];
            }
        }
        $filters[] = implode('', array_map(fn ($k) => "[v{$k}]", range(0, $count - 1)))."concat=n={$count}:v=1:a=0,tpad=stop_mode=clone:stop_duration=2[vc]";
        $mark = trim((string) ($options['watermark'] ?? ''));
        $fast && $mark !== '' && $events[] = [0, $total, 'Mark', mb_strtoupper($mark)];
        File::put("{$dir}/text.ass", self::ass($events));
        $filters[] = "[vc]subtitles='".self::escapePath("{$dir}/text.ass")."':fontsdir='".self::escapePath(resource_path('fonts'))."',format=yuv420p[v]";

        $seconds = self::seconds($total);
        $args = [...$args, '-i', "{$dir}/voice.wav"];
        $voiceIndex = $count;
        // Nhạc nền: bài trong thư viện nhạc của bạn, hoặc nhạc có sẵn trong public/nhac.
        $track = ($options['music_track_id'] ?? null) ? AudioTrack::where('user_id', $short->user_id)->find($options['music_track_id']) : null;
        $builtin = $track ? null : BuiltinMusic::path($options['music_file'] ?? null);
        $music = $track || $builtin;
        if ($music) {
            $local = "{$dir}/music.".(pathinfo($track?->path ?? $builtin, PATHINFO_EXTENSION) ?: 'mp3');
            $track ? File::put($local, (string) Media::disk()->get($track->path)) : File::copy($builtin, $local);
            $args = [...$args, '-stream_loop', '-1', '-i', $local];
            $volume = max(0, min(40, (int) ($options['music_volume'] ?? 12))) / 100;
            $filters[] = "[{$voiceIndex}:a]aresample=44100,aformat=channel_layouts=stereo,apad,atrim=0:{$seconds},asplit=2[voice][key]";
            $filters[] = '['.($voiceIndex + 1).":a]atrim=0:{$seconds},asetpts=PTS-STARTPTS,aresample=44100,aformat=channel_layouts=stereo,volume={$volume},afade=t=out:st=".self::seconds(max(0, $total - 2000)).':d=2[m0]';
            $filters[] = '[m0][key]sidechaincompress=threshold=0.02:ratio=6:attack=20:release=400[music]';
            $filters[] = '[voice][music]amix=inputs=2:normalize=0:duration=first[base]';
        } else {
            $filters[] = "[{$voiceIndex}:a]aresample=44100,aformat=channel_layouts=stereo,apad,atrim=0:{$seconds}[base]";
        }

        // Hiệu ứng âm thanh đặt ngay lúc đổi cảnh, theo tư thế khung manga.
        $effects = ($options['sfx'] ?? true) ? self::effects($scenes->values()->all(), $bounds) : [];
        $next = $voiceIndex + ($music ? 2 : 1);
        $volume = max(0, min(100, (int) ($options['sfx_volume'] ?? 45))) / 100;
        foreach ($effects as $k => [$name, $at]) {
            $args = [...$args, '-f', 'lavfi', '-i', self::SFX[$name]];
            $filters[] = '['.($next + $k).":a]aresample=44100,aformat=channel_layouts=stereo,volume={$volume},adelay={$at}|{$at}[s{$k}]";
        }
        $filters[] = $effects === []
            ? '[base]anull[a]'
            : '[base]'.implode('', array_map(fn ($k) => "[s{$k}]", array_keys($effects))).'amix=inputs='.(count($effects) + 1).':normalize=0:duration=first[a]';

        $out = "{$dir}/short.mp4";
        $result = Process::timeout(900)->run([
            config('videoaff.ffmpeg'), '-v', 'error', '-y', '-nostdin', ...$args, '-filter_complex', implode(';', $filters),
            '-map', '[v]', '-map', '[a]', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '21', '-pix_fmt', 'yuv420p', '-r', (string) self::FPS,
            '-c:a', 'aac', '-b:a', '160k', '-t', $seconds, '-movflags', '+faststart', $out,
        ]);
        if ($result->failed() || ! is_file($out)) {
            throw new RuntimeException('Dựng video lỗi: '.mb_substr(trim($result->errorOutput()), -400));
        }

        return ['path' => $out, 'ms' => $total];
    }

    /**
     * Ghi giọng kể vào voice.wav. Cảnh có giọng tự thu (mp3 tải lên) dùng file đó, các cảnh còn lại máy đọc;
     * ghép lại theo thứ tự cảnh: lặng 0,3 giây đầu, 0,25 giây giữa các cảnh, 0,8 giây cuối.
     *
     * @param  list<TopicShortScene>  $scenes
     * @return array{duration_ms: int, lines: list<array{start_ms: int, end_ms: int}>}
     *
     * @throws RuntimeException
     */
    private static function voice(TopicShort $short, array $scenes, string $dir, Tts $tts): array
    {
        $machine = array_values(array_filter($scenes, fn ($scene) => ! $scene->voice_path));
        if (count($machine) === count($scenes)) {
            $voice = $tts->speak(array_map(fn ($scene) => self::line($short, $scene->narration), $scenes), 0.3, 0.25, 0.8);
            File::put("{$dir}/voice.wav", $voice['contents']);

            return ['duration_ms' => $voice['duration_ms'], 'lines' => $voice['lines']];
        }

        // Máy đọc một lượt các cảnh không có giọng tự thu, rồi cắt từng câu ra theo mốc thời gian.
        $spoken = [];
        if ($machine !== []) {
            $voice = $tts->speak(array_map(fn ($scene) => self::line($short, $scene->narration), $machine), 0, 0.3, 0);
            File::put("{$dir}/tts.wav", $voice['contents']);
            foreach ($machine as $k => $scene) {
                $spoken[$scene->id] = $voice['lines'][$k] ?? throw new RuntimeException("Giọng máy thiếu câu của cảnh {$scene->position}.");
            }
        }

        $args = [];
        $filters = [];
        $lines = [];
        $at = 300;
        foreach ($scenes as $i => $scene) {
            if ($scene->voice_path) {
                $local = "{$dir}/voice{$i}.".(pathinfo($scene->voice_path, PATHINFO_EXTENSION) ?: 'mp3');
                $contents = Media::disk()->get($scene->voice_path);
                if ($contents === null) {
                    throw new RuntimeException("Không đọc được giọng tự thu của cảnh {$scene->position}.");
                }
                File::put($local, $contents);
                $ms = $scene->voice_ms ?: (int) round(EpisodeRenderer::duration($local) * 1000);
                $args = [...$args, '-i', $local];
                $trim = '';
            } else {
                [$from, $to] = [$spoken[$scene->id]['start_ms'], $spoken[$scene->id]['end_ms']];
                $ms = max(1, $to - $from);
                $args = [...$args, '-i', "{$dir}/tts.wav"];
                $trim = sprintf('atrim=start=%.3F:end=%.3F,asetpts=PTS-STARTPTS,', $from / 1000, $to / 1000);
            }
            $filters[] = "[{$i}:a]{$trim}aresample=44100,aformat=channel_layouts=mono,adelay={$at}|{$at}[c{$i}]";
            $lines[] = ['start_ms' => $at, 'end_ms' => $at + $ms];
            $at += $ms + 250;
        }
        $total = $at - 250 + 800;
        $count = count($scenes);
        $filters[] = implode('', array_map(fn ($i) => "[c{$i}]", range(0, $count - 1)))."amix=inputs={$count}:normalize=0:duration=longest,apad,atrim=0:".self::seconds($total).'[a]';

        $result = Process::timeout(300)->run([
            config('videoaff.ffmpeg'), '-v', 'error', '-y', '-nostdin', ...$args, '-filter_complex', implode(';', $filters),
            '-map', '[a]', '-c:a', 'pcm_s16le', "{$dir}/voice.wav",
        ]);
        if ($result->failed()) {
            throw new RuntimeException('Ghép giọng tự thu lỗi: '.mb_substr(trim($result->errorOutput()), -400));
        }

        return ['duration_ms' => $total, 'lines' => $lines];
    }

    /** Một câu đọc cho Tts: tiếng Việt dùng Piper (một giọng), tiếng Anh dùng giọng người kể Kokoro. */
    public static function line(TopicShort $short, string $text): array
    {
        return $short->language === 'en'
            ? ['text' => $text, 'voice' => Voices::NARRATOR['voice'], 'pitch' => Voices::NARRATOR['pitch'], 'speed' => Voices::NARRATOR['speed']]
            // Giọng Việt đánh vần chữ Anh như chữ Việt: đổi tên riêng sang cách đọc kiểu Việt (phụ đề vẫn giữ tên gốc).
            : ['text' => Pronounce::apply($text, $short->user_id), 'voice' => '', 'pitch' => 1.0, 'speed' => 1.0, 'lang' => 'vi'];
    }

    /**
     * Hiệu ứng mỗi cảnh: khung manga có tư thế hợp (khung đầu tiên khớp), không có thì cảnh "epic" dùng energy.
     *
     * @param  list<TopicShortScene>  $scenes
     * @param  list<int>  $bounds
     * @return list<array{0: string, 1: int}> [tên hiệu ứng, ms]
     */
    public static function effects(array $scenes, array $bounds): array
    {
        $effects = [];
        foreach ($scenes as $i => $scene) {
            $name = collect($scene->panels ?? [])->map(fn ($panel) => self::POSE_SFX[$panel['pose']] ?? (str_starts_with($panel['pose'], 'S') ? 'energy' : null))->filter()->first()
                ?? ($scene->mood === 'epic' ? 'energy' : null);
            $name && $effects[] = [$name, $bounds[$i] + ($i === 0 ? 300 : 80)];
        }

        return $effects;
    }

    /**
     * Gợi ý nhạc nền theo cảm xúc nhiều cảnh nhất: bài có tên chứa từ khoá của cảm xúc đó.
     *
     * @param  iterable<TopicShortScene>  $scenes
     * @param  iterable<AudioTrack>  $tracks
     * @param  list<array{file: string, name: string}>  $files  nhạc có sẵn (public/nhac)
     * @return array{mood: ?string, track_id: ?int, file: ?string}
     */
    public static function suggestMusic(iterable $scenes, iterable $tracks, array $files = []): array
    {
        $mood = collect($scenes)->pluck('mood')->filter()->countBy()->sortDesc()->keys()->first();
        $fits = fn (string $name) => $mood !== null && collect(self::MOOD_MUSIC[$mood] ?? [])->contains(fn ($word) => str_contains(mb_strtolower($name), $word));
        $track = collect($tracks)->first(fn ($track) => $fits($track->name));
        $file = $track ? null : collect($files)->first(fn ($f) => $fits($f['name']));

        return ['mood' => $mood, 'track_id' => $track?->id, 'file' => $file['file'] ?? null];
    }

    /**
     * Trang manga dọc: phóng nhẹ rồi lia từ khung trên xuống khung dưới.
     * Ảnh ngang: đặt giữa trên nền mờ của chính nó; ảnh dọc phủ kín. Zoom chậm, lần lượt vào / ra.
     */
    private static function sceneFilter(int $i, int $frames, bool $page = false): string
    {
        $w = self::WIDTH;
        $h = self::HEIGHT;
        if ($page) {
            return "[{$i}:v]scale={$w}:{$h}:force_original_aspect_ratio=increase,crop={$w}:{$h},scale=".($w * 2).':'.($h * 2).','
                ."zoompan=z='1.18':x='iw/2-(iw/zoom/2)':y='(ih-ih/zoom)*on/{$frames}':d={$frames}:s={$w}x{$h}:fps=".self::FPS.','
                ."setsar=1,format=yuv420p,fade=t=in:st=0:d=0.2[v{$i}]";
        }
        $bigW = (int) round($w * 1.5);
        $bigH = (int) round($h * 1.5);
        $zoom = $i % 2 === 0 ? "1+0.08*on/{$frames}" : "1.08-0.08*on/{$frames}";

        return "[{$i}:v]split=2[a{$i}][b{$i}];"
            ."[a{$i}]scale={$w}:{$h}:force_original_aspect_ratio=increase,crop={$w}:{$h},boxblur=30:3,eq=brightness=-0.2[bg{$i}];"
            ."[b{$i}]scale={$w}:{$h}:force_original_aspect_ratio=decrease[fg{$i}];"
            ."[bg{$i}][fg{$i}]overlay=(W-w)/2:(H-h)/2,scale={$bigW}:{$bigH},"
            ."zoompan=z='{$zoom}':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d={$frames}:s={$w}x{$h}:fps=".self::FPS.','
            ."setsar=1,format=yuv420p,fade=t=in:st=0:d=0.2[v{$i}]";
    }

    /**
     * Các cảnh quay của một cảnh (kiểu cắt nhanh): từng khung manga thật (tối đa số khung đủ 1,5 giây mỗi khung), không có thì ảnh cảnh.
     *
     * @return list<array{0: string, 1: int, 2: int}> [file tạm, bắt đầu ms, kết thúc ms]
     */
    private static function shots(TopicShortScene $scene, string $dir, int $i, int $from, int $to): array
    {
        $ids = array_map('intval', $scene->manga_panels ?? []);
        $paths = $ids === [] ? [] : MangaPanel::where('user_id', $scene->topicShort->user_id)->whereIn('id', $ids)->get()
            ->sortBy(fn (MangaPanel $panel) => array_search($panel->id, $ids, true))->pluck('image_path')->all();
        $paths = array_slice($paths ?: [$scene->image_path], 0, max(1, intdiv($to - $from, 1500)));
        $shots = [];
        $step = ($to - $from) / count($paths);
        foreach (array_values($paths) as $k => $path) {
            $contents = Media::disk()->get($path);
            if ($contents === null) {
                throw new RuntimeException("Không đọc được ảnh cảnh {$scene->position}.");
            }
            $local = "{$dir}/shot{$i}-{$k}.".(pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg');
            File::put($local, $contents);
            $shots[] = [$local, $from + (int) round($k * $step), $k === count($paths) - 1 ? $to : $from + (int) round(($k + 1) * $step)];
        }

        return $shots;
    }

    /**
     * Một cảnh quay phủ kín 9:16 có chuyển động: khung ngang lia từ phải sang trái (chiều đọc manga), khung dọc lia từ trên xuống,
     * khung gần 9:16 thì zoom vào / zoom ra xen kẽ.
     *
     * @return array{0: string, 1: list<string>} [filter, tham số input ffmpeg]
     */
    private static function shotFilter(int $k, string $local, int $frames): array
    {
        $w = self::WIDTH;
        $h = self::HEIGHT;
        [$iw, $ih] = (@getimagesize($local) ?: [$w, $h]);
        $ratio = $iw / max(1, $ih);
        $target = $w / $h;
        $loop = ['-loop', '1', '-framerate', (string) self::FPS, '-t', self::seconds((int) ceil($frames / self::FPS * 1000) + 200), '-i', $local];
        $end = ",trim=end_frame={$frames},setpts=PTS-STARTPTS,setsar=1,format=yuv420p[v{$k}]";
        if ($ratio > $target * 1.25) {
            return ["[{$k}:v]scale=-2:{$h},crop={$w}:{$h}:x='(iw-ow)*(1-n/{$frames})':y=0{$end}", $loop];
        }
        if ($ratio < $target / 1.25) {
            return ["[{$k}:v]scale={$w}:-2,crop={$w}:{$h}:x=0:y='(ih-oh)*n/{$frames}'{$end}", $loop];
        }
        $bigW = (int) round($w * 1.5);
        $bigH = (int) round($h * 1.5);
        $zoom = $k % 2 === 0 ? "1+0.22*on/{$frames}" : "1.22-0.22*on/{$frames}";

        return [
            "[{$k}:v]scale={$bigW}:{$bigH}:force_original_aspect_ratio=increase,crop={$bigW}:{$bigH},"
            ."zoompan=z='{$zoom}':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d={$frames}:s={$w}x{$h}:fps=".self::FPS.',setsar=1,format=yuv420p'."[v{$k}]",
            ['-i', $local],
        ];
    }

    /** File tạm của video ngắn của cảnh (đã xong), không có thì null. */
    private static function clip(TopicShortScene $scene, string $dir, int $i): ?string
    {
        if ($scene->clip_status !== 'ready' || ! $scene->clip_path) {
            return null;
        }
        $contents = Media::disk()->get($scene->clip_path);
        if ($contents === null) {
            return null;
        }
        $local = "{$dir}/clip{$i}.".(pathinfo($scene->clip_path, PATHINFO_EXTENSION) ?: 'mp4');
        File::put($local, $contents);

        return $local;
    }

    /**
     * Video ngắn phủ kín 9:16, lặp lại nếu ngắn hơn đoạn lời kể, bỏ tiếng của video.
     *
     * @return array{0: string, 1: list<string>} [filter, tham số input ffmpeg]
     */
    private static function clipFilter(int $k, string $local, int $frames): array
    {
        $w = self::WIDTH;
        $h = self::HEIGHT;

        return [
            "[{$k}:v]fps=".self::FPS.",scale={$w}:{$h}:force_original_aspect_ratio=increase,crop={$w}:{$h},"
            ."trim=end_frame={$frames},setpts=PTS-STARTPTS,setsar=1,format=yuv420p[v{$k}]",
            ['-stream_loop', '-1', '-i', $local],
        ];
    }

    /**
     * Phụ đề từng chữ (kiểu cắt nhanh): in hoa, bỏ dấu câu, thời gian chia theo độ dài chữ.
     *
     * @return list<array{0: int, 1: int, 2: string}>
     */
    public static function words(string $text, int $start, int $end): array
    {
        $words = array_values(array_filter(array_map(
            fn ($word) => mb_strtoupper(preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $word) ?? ''),
            preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [],
        ), fn ($word) => $word !== ''));
        if ($words === [] || $end <= $start) {
            return [];
        }
        $weights = array_map(fn ($word) => mb_strlen($word) + 2, $words);
        $total = array_sum($weights);
        $result = [];
        $cursor = $start;
        foreach ($words as $k => $word) {
            $next = $k === count($words) - 1 ? $end : $cursor + (int) round(($end - $start) * $weights[$k] / $total);
            $result[] = [$cursor, max($cursor + 1, $next), $word];
            $cursor = $next;
        }

        return $result;
    }

    /**
     * Chia câu đọc thành các dòng phụ đề ngắn, thời gian chia theo độ dài chữ.
     *
     * @return list<array{0: int, 1: int, 2: string}>
     */
    public static function chunks(string $text, int $start, int $end): array
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === [] || $end <= $start) {
            return [];
        }
        $groups = [];
        $current = [];
        foreach ($words as $word) {
            $current[] = $word;
            // Ngắt ở dấu câu hoặc khi đủ số từ.
            if (count($current) >= self::CHUNK_WORDS || (count($current) >= 3 && preg_match('/[.!?,;:…]$/u', $word))) {
                $groups[] = implode(' ', $current);
                $current = [];
            }
        }
        $current && $groups[] = implode(' ', $current);

        $totalChars = array_sum(array_map('mb_strlen', $groups));
        $chunks = [];
        $cursor = $start;
        foreach ($groups as $k => $group) {
            $next = $k === count($groups) - 1 ? $end : $cursor + (int) round(($end - $start) * mb_strlen($group) / max(1, $totalChars));
            $chunks[] = [$cursor, $next, $group];
            $cursor = $next;
        }

        return $chunks;
    }

    /** @param list<array{0: int, 1: int, 2: string, 3: string}> $events */
    private static function ass(array $events): string
    {
        $font = 'Be Vietnam Pro';
        $lines = [
            '[Script Info]', 'ScriptType: v4.00+', 'PlayResX: '.self::WIDTH, 'PlayResY: '.self::HEIGHT, 'WrapStyle: 0', 'ScaledBorderAndShadow: yes', '',
            '[V4+ Styles]',
            'Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding',
            "Style: Title,{$font},84,&H0000D7FF,&H00FFFFFF,&H00000000,&H64000000,-1,0,0,0,100,100,0,0,1,7,2,8,70,70,240,1",
            "Style: Top,{$font},72,&H00FFFFFF,&H00FFFFFF,&H00000000,&H96000000,-1,0,0,0,100,100,0,0,1,6,2,8,80,80,260,1",
            "Style: Sub,{$font},62,&H00FFFFFF,&H00FFFFFF,&H00000000,&H80000000,-1,0,0,0,100,100,0,0,1,5,1,2,80,80,520,1",
            // Kiểu cắt nhanh: một chữ một lần, in hoa, vàng viền đen ở giữa màn hình; tên kênh mờ ngay bên dưới.
            "Style: Word,{$font},124,&H0000D4FF,&H00FFFFFF,&H00000000,&H80000000,-1,0,0,0,100,100,0,0,1,9,4,5,40,40,0,1",
            "Style: Mark,{$font},58,&H7000D4FF,&H00FFFFFF,&H90000000,&H00000000,-1,0,0,0,100,100,2,0,1,2,0,5,40,40,0,1",
            '', '[Events]', 'Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text',
        ];
        foreach ($events as [$start, $end, $style, $text]) {
            $fade = match ($style) {
                'Sub' => '',
                'Word' => '{\fscx112\fscy112\t(0,90,\fscx100\fscy100)}',
                'Mark' => '{\pos('.intdiv(self::WIDTH, 2).','.(intdiv(self::HEIGHT, 2) + 130).')}',
                default => '{\fad(150,150)}',
            };
            $lines[] = 'Dialogue: 0,'.AssSubtitles::time($start).','.AssSubtitles::time($end).",{$style},,0,0,0,,{$fade}".AssSubtitles::escape($text);
        }

        return implode("\n", $lines)."\n";
    }

    private static function seconds(int $ms): string
    {
        return rtrim(rtrim(number_format($ms / 1000, 3, '.', ''), '0'), '.');
    }

    private static function escapePath(string $path): string
    {
        return str_replace(['\\', "'", ':'], ['\\\\', "\\'", '\\:'], $path);
    }
}
