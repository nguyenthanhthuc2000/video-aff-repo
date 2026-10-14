<?php

namespace App\Services\Film;

use App\Models\AudioTrack;
use App\Models\Episode;
use App\Models\Series;
use App\Models\Shot;
use App\Support\Media;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Ghép cả tập (16:9, 1920×1080, 24 khung/giây): thẻ tiêu đề → các shot theo thứ tự (video của shot; shot chưa có video thì
 * khung hình chuyển động nhẹ + lời thoại) → thẻ kết. Mờ qua đen ngắn ở chỗ chuyển phân đoạn, shot nối liền thì cắt thẳng.
 * Sau cùng trộn nhạc nền (nhỏ, dưới lời thoại) và phụ đề (.srt riêng, tuỳ chọn chèn vào hình).
 * Làm theo bước (từng đoạn → nối → hoàn thiện) để mỗi lượt job ngắn.
 */
final class EpisodeRenderer
{
    public const WIDTH = 1920;

    public const HEIGHT = 1080;

    public const FPS = 24;

    public const TITLE_MS = 3500;

    public const END_MS = 3000;

    /** Mờ qua đen ở chỗ chuyển phân đoạn (ms, mỗi bên). */
    public const FADE_MS = 300;

    public const DEFAULT_OPTIONS = [
        'title_card' => true, 'end_card' => true, 'burn_subtitles' => false, 'music_track_id' => null, 'music_volume' => 12, 'sound_effects' => true,
        'aspect' => '16:9', 'dialogue' => true, 'original_score' => false,
    ];

    /**
     * Khung xuất: ngang cho YouTube, dọc cho Shorts / TikTok / Reels. Phim khung ngang (shot vẽ 16:9): dọc thì giữ cả khung trên nền mờ, hoặc cắt giữa.
     * Phim khung dọc (truyện ngắn, shot vẽ 9:16): xuất dọc nguyên khung, hoặc ngang với nền mờ hai bên.
     */
    public const ASPECTS = [
        '16:9' => 'Ngang 16:9 (YouTube)',
        '9:16_blur' => 'Dọc 9:16 (Shorts): giữ cả khung, nền mờ trên dưới',
        '9:16_crop' => 'Dọc 9:16 (Shorts): cắt phần giữa khung',
        '9:16' => 'Dọc 9:16 nguyên khung (TikTok, Reels, Shorts)',
        '16:9_blur' => 'Ngang 16:9 (YouTube): giữ cả khung dọc, nền mờ hai bên',
    ];

    /** Khung xuất hợp với phim (theo khổ khung hình của phim). @return array<string, string> */
    public static function aspectsFor(Series $series): array
    {
        $keys = $series->vertical() ? ['9:16', '16:9_blur'] : ['16:9', '9:16_blur', '9:16_crop'];

        return array_intersect_key(self::ASPECTS, array_flip($keys));
    }

    /** Tuỳ chọn ghép mặc định của phim: truyện ngắn không thoại thì vào thẳng cảnh đầu (không thẻ tiêu đề / thẻ kết), khung dọc. */
    public static function defaults(Series $series): array
    {
        return [
            ...self::DEFAULT_OPTIONS,
            'aspect' => array_key_first(self::aspectsFor($series)),
            ...($series->silent() ? ['title_card' => false, 'end_card' => false, 'dialogue' => false] : []),
        ];
    }

    /** @return array{0: int, 1: int} */
    public static function size(string $aspect): array
    {
        return str_starts_with($aspect, '9:16') ? [1080, 1920] : [self::WIDTH, self::HEIGHT];
    }

    /** Khổ dựng shot / khung hình: theo khổ khung của phim. @return array{0: int, 1: int} */
    public static function canvas(Series $series): array
    {
        return $series->vertical() ? [1080, 1920] : [self::WIDTH, self::HEIGHT];
    }

    /** Từ khung dựng [src] (ngang 1920×1080 hoặc dọc 1080×1920) sang khung xuất [v]. */
    private static function reframe(string $aspect, bool $verticalSource = false): string
    {
        if ($verticalSource) {
            return $aspect === '16:9_blur'
                ? '[src]split=2[bg0][fg0];[bg0]scale=1920:1080:force_original_aspect_ratio=increase,crop=1920:1080,boxblur=24:2,eq=brightness=-0.12[bg];'
                    .'[fg0]scale=-2:1080[fg];[bg][fg]overlay=(W-w)/2:(H-h)/2,setsar=1,format=yuv420p[v]'
                : '[src]null[v]';
        }

        return match ($aspect) {
            '9:16_crop' => '[src]crop=ih*9/16:ih,scale=1080:1920,setsar=1[v]',
            '9:16_blur' => '[src]split=2[bg0][fg0];[bg0]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,boxblur=24:2,eq=brightness=-0.12[bg];'
                .'[fg0]scale=1080:-2[fg];[bg][fg]overlay=(W-w)/2:(H-h)/2,setsar=1,format=yuv420p[v]',
            default => '[src]null[v]',
        };
    }

    /**
     * Danh sách đoạn của tập theo thứ tự.
     *
     * @return list<array{type: string, shot_id?: int, duration_ms: int, fade_in: bool, fade_out: bool, offset_ms: int}>
     */
    public static function plan(Episode $episode, array $options): array
    {
        $shots = $episode->shots()->get();
        $aspects = self::aspectsFor($episode->series);
        $segments = [];
        if ($options['title_card'] ?? true) {
            $segments[] = ['type' => 'title', 'duration_ms' => self::TITLE_MS, 'fade_in' => true, 'fade_out' => true];
        }
        foreach ($shots as $i => $shot) {
            $next = $shots[$i + 1] ?? null;
            $previous = $shots[$i - 1] ?? null;
            $segments[] = [
                'type' => $shot->video_path ? 'shot' : 'still',
                'shot_id' => $shot->id,
                'duration_ms' => $shot->duration_ms,
                // Đầu / cuối phân đoạn thì mờ qua đen; trong phân đoạn cắt thẳng.
                'fade_in' => $previous === null || ($previous->sequence !== $shot->sequence && ! $shot->continues_previous),
                'fade_out' => $next === null || ($next->sequence !== $shot->sequence && ! $next->continues_previous),
            ];
        }
        if ($options['end_card'] ?? true) {
            $segments[] = ['type' => 'end', 'duration_ms' => self::END_MS, 'fade_in' => true, 'fade_out' => true];
        }
        $offset = 0;
        foreach ($segments as &$segment) {
            // Bản lồng tiếng Việt: tiếng từng shot là tiếng Việt, thẻ tiêu đề / thẻ kết bằng tiếng Việt.
            $segment['lang'] = $options['lang'] ?? 'en';
            $segment['aspect'] = isset($aspects[$options['aspect'] ?? '']) ? $options['aspect'] : array_key_first($aspects);
            // Không lời thoại: chỉ giữ hình, nhạc nền và hiệu ứng (bản tiếng Việt luôn có lời).
            $segment['dialogue'] = ($options['dialogue'] ?? true) || $segment['lang'] === 'vi';
            $segment['offset_ms'] = $offset;
            $offset += $segment['duration_ms'];
        }

        return $segments;
    }

    /**
     * Mã hoá một đoạn thành file cùng thông số (để nối không cần mã hoá lại).
     *
     * @throws RuntimeException
     */
    public static function segment(Episode $episode, array $segment, string $output, string $dir): void
    {
        $seconds = self::seconds($segment['duration_ms']);
        [$w, $h] = self::canvas($episode->series);
        $fade = self::fades($segment);
        $shot = isset($segment['shot_id']) ? Shot::find($segment['shot_id']) : null;
        $vi = ($segment['lang'] ?? 'en') === 'vi';
        $silent = ! ($segment['dialogue'] ?? true);
        $aspect = $segment['aspect'] ?? '16:9';

        [$inputs, $video, $audio] = match ($segment['type']) {
            'shot' => [
                ['-i', self::local($shot->video_path, "{$dir}/in.mp4"), ...($vi ? self::dubInput($shot, $dir, $seconds) : []), ...($silent ? ['-f', 'lavfi', '-t', $seconds, '-i', 'anullsrc=r=44100:cl=stereo'] : [])],
                "[0:v]scale={$w}:{$h}:force_original_aspect_ratio=increase,crop={$w}:{$h},fps=".self::FPS.",setsar=1,tpad=stop_mode=clone:stop_duration={$seconds},trim=duration={$seconds},setpts=PTS-STARTPTS{$fade['v']},format=yuv420p[v]",
                '['.($vi || $silent ? 1 : 0).":a]aresample=44100,aformat=channel_layouts=stereo,apad=whole_dur={$seconds},atrim=duration={$seconds}{$fade['a']}[a]",
            ],
            'still' => self::still($shot, $segment, $dir, $seconds, $fade, $w, $h),
            default => self::card($episode, $segment, $dir, $seconds, $fade),
        };
        // Shot / khung hình dựng ở khung ngang rồi chuyển sang khung xuất; thẻ tiêu đề / kết dựng thẳng ở khung xuất.
        if ($segment['type'] === 'shot' || $segment['type'] === 'still') {
            $video = substr($video, 0, -3).'[src];'.self::reframe($aspect, $episode->series->vertical());
        }

        self::ffmpeg([
            ...$inputs,
            '-filter_complex', "{$video};{$audio}", '-map', '[v]', '-map', '[a]',
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-pix_fmt', 'yuv420p', '-r', (string) self::FPS,
            '-c:a', 'aac', '-b:a', '160k', '-ar', '44100', '-ac', '2', '-t', $seconds, $output,
        ], 300);
    }

    /**
     * Nối các đoạn, trộn nhạc nền, chèn phụ đề nếu chọn; trả đường dẫn file kết quả.
     *
     * @param  list<string>  $files
     *
     * @throws RuntimeException
     */
    public static function finish(Episode $episode, array $files, array $options, string $srt, string $dir, array $plan = []): string
    {
        File::put("{$dir}/list.txt", implode("\n", array_map(fn ($f) => "file '{$f}'", $files))."\n");
        $joined = "{$dir}/joined.mp4";
        self::ffmpeg(['-f', 'concat', '-safe', '0', '-i', "{$dir}/list.txt", '-c', 'copy', $joined], 600);

        // Nhạc nền: nhạc riêng Claude sáng tác cho tập (theo từng phân đoạn), hoặc một bài trong thư viện Âm thanh.
        $track = ($options['music_track_id'] ?? null) ? AudioTrack::where('user_id', $episode->series->user_id)->find($options['music_track_id']) : null;
        $music = ($options['original_score'] ?? false)
            ? EpisodeScore::track($episode, $plan, $dir)
            : ($track ? self::local($track->path, "{$dir}/music.".(pathinfo($track->path, PATHINFO_EXTENSION) ?: 'mp3')) : null);
        $burn = (bool) ($options['burn_subtitles'] ?? false) && (($options['dialogue'] ?? true) || ($options['lang'] ?? 'en') === 'vi') && trim(File::get($srt)) !== '';
        $vertical = str_starts_with((string) ($options['aspect'] ?? ''), '9:16');
        $totalMs = (int) round(self::duration($joined) * 1000);
        $sfx = ($options['sound_effects'] ?? true) ? self::effects($episode, $plan, $dir, $totalMs) : null;
        if ($music === null && ! $burn && $sfx === null) {
            return $joined;
        }

        $out = "{$dir}/episode.mp4";
        $total = self::seconds($totalMs);
        $args = ['-i', $joined];
        $musicIndex = null;
        if ($music) {
            $args = [...$args, '-stream_loop', '-1', '-i', $music];
            $musicIndex = 1;
        }
        $sfxIndex = null;
        if ($sfx) {
            $args = [...$args, '-i', $sfx];
            $sfxIndex = $music ? 2 : 1;
        }
        $filters = [];
        $maps = [];
        if ($burn) {
            // Khung dọc: chữ nhỏ hơn (cỡ tính theo chiều cao 288 của libass) và cao hơn để không bị nút / tên kênh của Shorts che.
            $style = $vertical
                ? 'FontName=Be Vietnam Pro,FontSize=11,PrimaryColour=&H00FFFFFF,OutlineColour=&H00000000,BorderStyle=1,Outline=1.5,Shadow=0,MarginV=75,MarginL=12,MarginR=12'
                : 'FontName=Be Vietnam Pro,FontSize=20,PrimaryColour=&H00FFFFFF,OutlineColour=&H00000000,BorderStyle=1,Outline=2,Shadow=0,MarginV=36';
            $filters[] = "[0:v]subtitles='".self::escape($srt)."':fontsdir='".self::escape(resource_path('fonts'))."':force_style='{$style}'[v]";
            $maps = ['-map', '[v]'];
            $video = ['-c:v', 'libx264', '-preset', 'medium', '-crf', '20', '-pix_fmt', 'yuv420p'];
        } else {
            $maps = ['-map', '0:v'];
            $video = ['-c:v', 'copy'];
        }
        if ($music || $sfx) {
            // Lời thoại giữ nguyên; nhạc nền tự nhỏ đi khi có lời (sidechain), hiệu ứng trộn đúng mốc.
            $mix = ['[dlg]'];
            $filters[] = $music ? '[0:a]asplit=2[dlg][key]' : '[0:a]anull[dlg]';
            if ($music) {
                $volume = max(0, min(100, (int) ($options['music_volume'] ?? 12))) / 100;
                // Bản không lời: nhạc là tiếng chính, to hơn hẳn mức nền dưới lời thoại.
                if (! ($options['dialogue'] ?? true) && ($options['lang'] ?? 'en') !== 'vi') {
                    $volume = min(1.0, round($volume * 2.5, 2));
                }
                $fadeOut = self::seconds(max(0, $totalMs - 2500));
                $filters[] = "[{$musicIndex}:a]atrim=0:{$total},asetpts=PTS-STARTPTS,aresample=44100,aformat=channel_layouts=stereo,volume={$volume},afade=t=in:d=1.5,afade=t=out:st={$fadeOut}:d=2.5[music0]";
                $filters[] = '[music0][key]sidechaincompress=threshold=0.02:ratio=6:attack=20:release=400[music]';
                $mix[] = '[music]';
            }
            if ($sfx) {
                $filters[] = "[{$sfxIndex}:a]aresample=44100,aformat=channel_layouts=stereo[sfx]";
                $mix[] = '[sfx]';
            }
            $filters[] = implode('', $mix).'amix=inputs='.count($mix).':duration=first:normalize=0[a]';
            $maps = [...$maps, '-map', '[a]'];
            $audio = ['-c:a', 'aac', '-b:a', '192k'];
        } else {
            $maps = [...$maps, '-map', '0:a'];
            $audio = ['-c:a', 'copy'];
        }
        self::ffmpeg([...$args, '-filter_complex', implode(';', $filters), ...$maps, ...$video, ...$audio, '-t', $total, '-movflags', '+faststart', $out], 840);

        return $out;
    }

    /**
     * Rải âm thanh hiệu ứng theo danh sách của tập thành một file tiếng cả tập (null nếu không có).
     *
     * @throws RuntimeException
     */
    public static function effects(Episode $episode, array $plan, string $dir, int $totalMs): ?string
    {
        $library = SoundLibrary::byId();
        $offsets = [];
        foreach ($plan as $segment) {
            $offsets[isset($segment['shot_id']) ? 'shot:'.$segment['shot_id'] : $segment['type']] = $segment['offset_ms'];
        }
        $events = [];
        $characters = $episode->series->characters()->get()->keyBy('id');
        foreach ($episode->sound_plan ?? [] as $event) {
            $key = $event['place'] === 'shot' ? 'shot:'.$event['shot_id'] : $event['place'];
            $sound = $library[$event['sound']] ?? null;
            if (! isset($offsets[$key]) || $sound === null) {
                continue;
            }
            // Âm thanh có nhiều biến thể: chọn cố định theo vị trí để ghép lại vẫn y như cũ.
            $file = $sound['files'][crc32($key.':'.$event['at_ms']) % count($sound['files'])];
            // Tiếng của nhân vật: đổi cao độ riêng theo nhân vật (cười, khóc, tiếng kêu của mỗi nhân vật một kiểu).
            $character = isset($event['character_id']) ? $characters->get($event['character_id']) : null;
            $pitch = $character ? CharacterSounds::filter(CharacterSounds::pitch($character, $sound['group'])) : 'anull';
            $events[] = ['file' => $file, 'ms' => $offsets[$key] + (int) $event['at_ms'], 'volume' => (float) $event['volume'], 'pitch' => $pitch];
        }
        if ($events === []) {
            return null;
        }

        $local = [];
        $chunks = [];
        foreach (array_chunk($events, 40) as $c => $chunk) {
            $inputs = [];
            $filters = [];
            foreach ($chunk as $i => $event) {
                $local[$event['file']] ??= self::local($event['file'], "{$dir}/sfx-".count($local).'.'.pathinfo($event['file'], PATHINFO_EXTENSION));
                $inputs = [...$inputs, '-i', $local[$event['file']]];
                $filters[] = "[{$i}:a]aresample=44100,aformat=channel_layouts=stereo,".($event['pitch'] ?? 'anull').",volume={$event['volume']},adelay={$event['ms']}:all=1[e{$i}]";
            }
            $filters[] = implode('', array_map(fn ($i) => "[e{$i}]", array_keys($chunk))).'amix=inputs='.count($chunk).':normalize=0:duration=longest,apad=whole_dur='.self::seconds($totalMs).'[out]';
            $chunks[] = $file = "{$dir}/sfx-chunk-{$c}.wav";
            self::ffmpeg([...$inputs, '-filter_complex', implode(';', $filters), '-map', '[out]', '-t', self::seconds($totalMs), $file], 300);
        }
        if (count($chunks) === 1) {
            return $chunks[0];
        }
        $out = "{$dir}/sfx.wav";
        self::ffmpeg([...collect($chunks)->flatMap(fn ($f) => ['-i', $f])->all(), '-filter_complex', implode('', array_map(fn ($i) => "[{$i}:a]", array_keys($chunks))).'amix=inputs='.count($chunks).':normalize=0:duration=longest[out]', '-map', '[out]', $out], 300);

        return $out;
    }

    /** Phụ đề .srt của cả tập theo mốc từng câu (tiếng đã khớp khẩu hình nếu có, không thì theo tiếng đọc sẵn). */
    public static function srt(Episode $episode, array $plan): string
    {
        $shots = $episode->shots()->get()->keyBy('id');
        $cues = [];
        foreach ($plan as $segment) {
            $shot = isset($segment['shot_id']) ? $shots[$segment['shot_id']] : null;
            if ($shot === null) {
                continue;
            }
            $lines = collect($shot->dialogue ?? [])->filter(fn ($d) => trim((string) ($d['line'] ?? '')) !== '')->values();
            // Bản tiếng Việt: phụ đề tiếng Anh theo mốc câu tiếng Việt đã lồng.
            $times = ($segment['lang'] ?? 'en') === 'vi' && $shot->dub_vi_lines ? $shot->dub_vi_lines : ($shot->audio_lines ?? []);
            foreach ($lines as $i => $line) {
                // Không có mốc (chưa đọc): chia đều thời lượng shot cho các câu.
                $start = $times[$i]['start_ms'] ?? (int) ($shot->duration_ms * $i / max(1, $lines->count()));
                $end = $times[$i]['end_ms'] ?? (int) ($shot->duration_ms * ($i + 1) / max(1, $lines->count()));
                $cues[] = [$segment['offset_ms'] + $start, $segment['offset_ms'] + min($end + 300, $shot->duration_ms), trim((string) $line['line'])];
            }
        }

        return collect($cues)->map(fn ($cue, $i) => ($i + 1)."\n".self::srtTime($cue[0]).' --> '.self::srtTime($cue[1])."\n".$cue[2]."\n")->implode("\n");
    }

    /** Đầu vào tiếng Việt của shot (shot không lời: im lặng). @return list<string> */
    private static function dubInput(Shot $shot, string $dir, string $seconds): array
    {
        return $shot->dub_vi_path
            ? ['-i', self::local($shot->dub_vi_path, "{$dir}/vi.wav")]
            : ['-f', 'lavfi', '-t', $seconds, '-i', 'anullsrc=r=44100:cl=stereo'];
    }

    /** @return array{0: list<string>, 1: string, 2: string} */
    private static function still(Shot $shot, array $segment, string $dir, string $seconds, array $fade, int $w, int $h): array
    {
        $frames = max(1, (int) round($segment['duration_ms'] / 1000 * self::FPS));
        $image = self::local((string) $shot->image_path, "{$dir}/still.".(pathinfo((string) $shot->image_path, PATHINFO_EXTENSION) ?: 'png'));
        $voice = ($segment['dialogue'] ?? true) ? ($shot->synced_audio_path ?? $shot->audio_path) : null;
        $inputs = ['-loop', '1', '-i', $image];
        $inputs = match (true) {
            ($segment['lang'] ?? 'en') === 'vi' => [...$inputs, ...self::dubInput($shot, $dir, $seconds)],
            $voice !== null => [...$inputs, '-i', self::local($voice, "{$dir}/voice.wav")],
            default => [...$inputs, '-f', 'lavfi', '-t', $seconds, '-i', 'anullsrc=r=44100:cl=stereo'],
        };
        $bw = (int) round($w * 1.2);
        $bh = (int) round($h * 1.2);

        return [
            $inputs,
            "[0:v]scale={$bw}:{$bh}:force_original_aspect_ratio=increase,crop={$bw}:{$bh},".self::move($shot->camera_move, $frames)
                .":d={$frames}:s={$w}x{$h}:fps=".self::FPS.",setsar=1,trim=duration={$seconds}{$fade['v']},format=yuv420p[v]",
            "[1:a]aresample=44100,aformat=channel_layouts=stereo,apad=whole_dur={$seconds},atrim=duration={$seconds}{$fade['a']}[a]",
        ];
    }

    /** Chuyển động máy trên ảnh tĩnh (zoompan): mặc định đẩy vào nhẹ; truyện ngắn theo chuyển động Claude chọn cho shot. */
    public static function move(?string $move, int $frames): string
    {
        $t = "on/{$frames}";
        $center = ":x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)'";

        return match ($move) {
            'static' => "zoompan=z='1.02'{$center}",
            'push_in' => "zoompan=z='1+0.08*{$t}'{$center}",
            'pull_out' => "zoompan=z='1.08-0.08*{$t}'{$center}",
            'pan_left' => "zoompan=z='1.12':x='(iw-iw/zoom)*(1-{$t})':y='ih/2-(ih/zoom/2)'",
            'pan_right' => "zoompan=z='1.12':x='(iw-iw/zoom)*{$t}':y='ih/2-(ih/zoom/2)'",
            'tilt_up' => "zoompan=z='1.12':x='iw/2-(iw/zoom/2)':y='(ih-ih/zoom)*(1-{$t})'",
            'tilt_down' => "zoompan=z='1.12':x='iw/2-(iw/zoom/2)':y='(ih-ih/zoom)*{$t}'",
            default => "zoompan=z='1+0.05*{$t}'{$center}",
        };
    }

    /** Thẻ tiêu đề / thẻ kết: khung hình đầu (hoặc cuối) làm mờ, tối đi, chữ ở giữa. @return array{0: list<string>, 1: string, 2: string} */
    private static function card(Episode $episode, array $segment, string $dir, string $seconds, array $fade): array
    {
        [$w, $h] = self::size($segment['aspect'] ?? '16:9');
        $shots = $episode->shots()->whereNotNull('image_path')->get();
        $source = ($segment['type'] === 'title' ? $shots->first() : $shots->last())?->image_path;
        [$big, $small] = match (true) {
            ($segment['lang'] ?? 'en') === 'vi' => $segment['type'] === 'title'
                ? [$episode->series->title, "Tập {$episode->number}: ".($episode->dub_vi['title'] ?? $episode->title)]
                : ['Cảm ơn các bạn đã xem!', "Hẹn gặp lại ở tập sau của {$episode->series->title}"],
            $segment['type'] === 'title' => [$episode->series->title, "Episode {$episode->number}: {$episode->title}"],
            default => ['Thanks for watching!', "See you next time on {$episode->series->title}"],
        };
        // Font không có emoji: bỏ đi để không in ra ô vuông.
        [$big, $small] = [Thumbnail::plain($big), Thumbnail::plain($small)];
        File::put("{$dir}/big.txt", $big);
        File::put("{$dir}/small.txt", $small);
        $font = self::escape(resource_path('fonts/BeVietnamPro-Bold.ttf'));
        $semi = self::escape(resource_path('fonts/BeVietnamPro-SemiBold.ttf'));
        // Chữ không xuống dòng: dòng dài (hoặc khung dọc hẹp) thì thu nhỏ cho vừa bề ngang.
        $bigSize = (int) min(96, floor(($w - 120) / max(1, mb_strlen($big) * 0.56)));
        $smallSize = (int) min(52, floor(($w - 100) / max(1, mb_strlen($small) * 0.54)));
        $text = "drawtext=fontfile='{$font}':textfile='".self::escape("{$dir}/big.txt")."':fontsize={$bigSize}:fontcolor=white:borderw=4:bordercolor=black@0.6:x=(w-text_w)/2:y=(h/2)-text_h-10,"
            ."drawtext=fontfile='{$semi}':textfile='".self::escape("{$dir}/small.txt")."':fontsize={$smallSize}:fontcolor=white:borderw=3:bordercolor=black@0.6:x=(w-text_w)/2:y=(h/2)+30";
        $inputs = $source
            ? ['-loop', '1', '-i', self::local($source, "{$dir}/card.".(pathinfo($source, PATHINFO_EXTENSION) ?: 'png'))]
            : ['-f', 'lavfi', '-i', "color=c=0x1e293b:s={$w}x{$h}:r=".self::FPS];

        return [
            [...$inputs, '-f', 'lavfi', '-t', $seconds, '-i', 'anullsrc=r=44100:cl=stereo'],
            "[0:v]scale={$w}:{$h}:force_original_aspect_ratio=increase,crop={$w}:{$h},boxblur=20:2,eq=brightness=-0.18,fps=".self::FPS.",setsar=1,{$text},trim=duration={$seconds},setpts=PTS-STARTPTS{$fade['v']},format=yuv420p[v]",
            "[1:a]atrim=duration={$seconds}[a]",
        ];
    }

    /** @return array{v: string, a: string} */
    private static function fades(array $segment): array
    {
        $d = self::seconds(self::FADE_MS);
        $out = self::seconds(max(0, $segment['duration_ms'] - self::FADE_MS));
        $v = ($segment['fade_in'] ? ",fade=t=in:st=0:d={$d}" : '').($segment['fade_out'] ? ",fade=t=out:st={$out}:d={$d}" : '');
        $a = ($segment['fade_in'] ? ",afade=t=in:st=0:d={$d}" : '').($segment['fade_out'] ? ",afade=t=out:st={$out}:d={$d}" : '');

        return ['v' => $v, 'a' => $a];
    }

    private static function local(string $mediaPath, string $target): string
    {
        $contents = Media::disk()->get($mediaPath);
        if ($contents === null) {
            throw new RuntimeException("Không tìm thấy file {$mediaPath}.");
        }
        File::put($target, $contents);

        return $target;
    }

    public static function duration(string $file): float
    {
        $result = Process::timeout(30)->run([config('videoaff.ffprobe'), '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $file]);

        return (float) trim($result->output());
    }

    /** @param list<string> $args */
    private static function ffmpeg(array $args, int $timeout): void
    {
        $result = Process::timeout($timeout)->run([config('videoaff.ffmpeg'), '-v', 'error', '-y', '-nostdin', ...$args]);
        if ($result->failed()) {
            throw new RuntimeException('Ghép tập lỗi: '.mb_substr(trim($result->errorOutput()), -400));
        }
    }

    private static function srtTime(int $ms): string
    {
        return sprintf('%02d:%02d:%02d,%03d', intdiv($ms, 3600000), intdiv($ms % 3600000, 60000), intdiv($ms % 60000, 1000), $ms % 1000);
    }

    private static function seconds(int $ms): string
    {
        return rtrim(rtrim(number_format($ms / 1000, 3, '.', ''), '0'), '.') ?: '0';
    }

    private static function escape(string $path): string
    {
        return str_replace(['\\', "'", ':'], ['\\\\', "\\'", '\\:'], $path);
    }
}
