<?php

namespace App\Services\English;

use App\Models\AudioTrack;
use App\Models\EnglishLesson;
use App\Models\EnglishRecording;
use App\Services\Film\SoundLibrary;
use App\Support\Media;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Video Shorts dọc 1080×1920 từ bài học + giọng người học. Chữ vẽ bằng phụ đề ASS (chữ chạy theo giọng mẫu, tô màu từ cần sửa),
 * nền chuyển màu theo tuần, tiếng = giọng mẫu + giọng người học + hiệu ứng nhỏ (+ nhạc nền tuỳ chọn, tự nhỏ khi có lời).
 * 5 câu: mở đầu "DAY N" → mỗi câu (giọng mẫu, rồi "Mình đọc lại" kèm điểm; chưa thu thì "Đến lượt bạn") → câu hỏi cho người xem.
 * Nói 30 giây: mở đầu → bài nói kèm phụ đề → từng lỗi "Mình nói / Nên nói" (giọng mẫu đọc câu đúng) → lời khen → kết.
 */
final class LessonVideo
{
    public const WIDTH = 1080;

    public const HEIGHT = 1920;

    public const FPS = 30;

    public const INTRO_MS = 2800;

    public const OUTRO_MS = 4000;

    /** Dời mọi dòng chữ xuống (bố cục lệch giữa, chừa chỗ cho tên kênh / nút của Shorts ở dưới). */
    private const Y_OFFSET = 120;

    /** Màu nền (đầu, cuối) theo tuần. */
    private const PALETTES = [
        ['0x1e3a8a', '0x6d28d9'], ['0x0f766e', '0x1d4ed8'], ['0x9a3412', '0xbe185d'], ['0x166534', '0x0e7490'],
        ['0x7c2d12', '0x6d28d9'], ['0x1e40af', '0x0f766e'], ['0x86198f', '0x1e3a8a'], ['0x155e75', '0x4d7c0f'],
        ['0x9f1239', '0x7c3aed'], ['0x1f2937', '0x0369a1'], ['0x713f12', '0xb91c1c'], ['0x312e81', '0xbe123c'],
    ];

    private array $events = [];

    private array $audio = [];

    private int $cursor = 0;

    private function __construct(private EnglishLesson $lesson, private string $dir) {}

    /**
     * Dựng video, trả đường dẫn file mp4 trong $dir.
     *
     * @throws RuntimeException
     */
    public static function render(EnglishLesson $lesson, string $dir, array $options = []): string
    {
        $video = new self($lesson, $dir);
        $video->intro();
        $lesson->kind === 'speaking' ? $video->speaking() : $video->sentences();
        $video->outro();

        return $video->encode($options);
    }

    private function intro(): void
    {
        $info = Curriculum::day($this->lesson->day);
        $title = $this->lesson->kind === 'speaking' ? 'NÓI 30 GIÂY' : '5 CÂU MỖI NGÀY';
        $this->text(0, self::INTRO_MS, 'Day', 'DAY '.$this->lesson->day, 540, 640, '\fad(150,200)\fscx60\fscy60\t(0,250,\fscx100\fscy100)');
        $this->text(0, self::INTRO_MS, 'Small', "{$title}  ·  {$info['level']} → B1", 540, 800);
        $this->text(150, self::INTRO_MS, 'Big', $this->lesson->topic, 540, 980, '\fad(200,200)');
        $this->text(300, self::INTRO_MS, 'Vi', $this->lesson->topic_vi, 540, 1130, '\fad(200,200)');
        $this->sfx('jingles_pizzi_1', 0, 0.5);
        $this->cursor = self::INTRO_MS;
    }

    private function sentences(): void
    {
        $content = $this->lesson->content;
        $best = $this->lesson->bestRecordings();
        $count = count($content['sentences']);
        foreach ($content['sentences'] as $i => $sentence) {
            $model = $content['audio'][$i] ?? null;
            $start = $this->cursor;
            $label = ($i + 1)."/{$count}";

            // Giọng mẫu: chữ sáng dần theo giọng.
            $modelMs = $model ? $model['ms'] : 2500;
            $model && $this->clip($model['path'], $start, 1.0);
            $partEnd = $start + $modelMs + 500;
            $this->text($start, $partEnd, 'Label', "{$label}  ·  GIỌNG MẪU", 540, 520);
            $this->karaoke($start + 50, $partEnd, $sentence['en'], max(600, $modelMs - 150), 540, 800);
            $this->sentenceDetails($start, $partEnd, $sentence, false);
            $this->cursor = $partEnd;

            /** @var ?EnglishRecording $mine */
            $mine = $best[$i] ?? null;
            $start = $this->cursor;
            if ($mine === null) {
                $end = $start + 2600;
                $this->text($start, $end, 'Label', "{$label}  ·  ĐẾN LƯỢT BẠN!", 540, 520, '\fad(100,100)');
                $this->text($start, $end, 'Big', $this->escape($sentence['en']), 540, 800);
                $this->sentenceDetails($start, $end, $sentence, true);
            } else {
                [$from, $to] = self::speechSpan($mine);
                $this->clip($mine->path, $start, 1.0, $from, $to);
                $end = $start + ($to - $from) + 900;
                $result = Pronunciation::score($sentence['en'], $mine->words['heard'] ?? []);
                $this->text($start, $end, 'Label', "{$label}  ·  MÌNH ĐỌC LẠI", 540, 520);
                $this->text($start, $end, 'Big', $this->marked($result['words']), 540, 800);
                $this->sentenceDetails($start, $end, $sentence, true);
                $scoreAt = $start + ($to - $from) + 100;
                $color = $mine->score >= 8 ? '&H0080E04A&' : ($mine->score >= 5 ? '&H0000D4FF&' : '&H006B6BFF&');
                $this->text($scoreAt, $end, 'Score', "{$mine->score}/10", 540, 1480, "\\c{$color}\\fad(80,0)\\fscx70\\fscy70\\t(0,180,\\fscx100\\fscy100)");
                $this->sfx($mine->score >= 8 ? 'interface_confirmation' : 'interface_question', $scoreAt, 0.45);
            }
            $this->cursor = $end;
        }
    }

    /** Nghĩa tiếng Việt, từ khó + IPA, mẹo (lượt đọc lại). */
    private function sentenceDetails(int $start, int $end, array $sentence, bool $tip): void
    {
        $this->text($start, $end, 'Vi', $this->escape($sentence['vi']), 540, 1060);
        if (filled($sentence['key_word'])) {
            $this->text($start, $end, 'Ipa', $this->escape("{$sentence['key_word']}  {$sentence['key_ipa']}"), 540, 1220);
        }
        if ($tip && filled($sentence['tip_vi'])) {
            $this->text($start, $end, 'Tip', $this->escape($sentence['tip_vi']), 540, 1330);
        }
    }

    private function speaking(): void
    {
        $content = $this->lesson->content;
        $feedback = $this->lesson->feedback ?? [];
        $mine = $this->lesson->bestRecordings()[0] ?? null;
        $start = $this->cursor;

        $this->text($start, $start + 2600, 'Label', 'ĐỀ BÀI', 540, 520);
        $this->text($start, $start + 2600, 'Big', $this->escape($content['prompt']), 540, 820);
        $this->text($start, $start + 2600, 'Vi', $this->escape($content['prompt_vi']), 540, 1080);
        $this->cursor = $start + 2600;

        if ($mine) {
            [$from, $to] = self::speechSpan($mine);
            $to = min($to, $from + 75000);
            $start = $this->cursor;
            $this->clip($mine->path, $start, 1.0, $from, $to);
            $this->text($start, $start + ($to - $from) + 300, 'Label', 'MÌNH NÓI', 540, 520);
            foreach ($mine->segments ?? [] as $segment) {
                if ($segment['end_ms'] <= $from || $segment['start_ms'] >= $to) {
                    continue;
                }
                $this->text($start + max(0, $segment['start_ms'] - $from), $start + min($to, $segment['end_ms']) - $from + 150, 'Sub', $this->escape($segment['text']), 540, 1000);
            }
            $this->cursor = $start + ($to - $from) + 500;
        }

        $corrections = $feedback['corrections'] ?? [];
        foreach ($corrections as $i => $correction) {
            $model = $feedback['audio'][$i] ?? null;
            $start = $this->cursor;
            $modelMs = $model ? $model['ms'] : 2500;
            $end = $start + 900 + $modelMs + 1800;
            $this->text($start, $end, 'Label', 'SỬA LỖI '.($i + 1).'/'.count($corrections), 540, 520);
            $this->text($start, $end, 'Tip', 'Mình nói:', 540, 640);
            $this->text($start, $end, 'Wrong', $this->escape($correction['said']), 540, 760);
            $this->text($start + 600, $end, 'Tip', 'Nên nói:', 540, 940, '\fad(150,0)');
            $model && $this->clip($model['path'], $start + 900, 1.0);
            $this->karaoke($start + 950, $end, $correction['better'], max(600, $modelMs - 150), 540, 1080, 'Good');
            $this->text($start + 900, $end, 'Vi', $this->escape($correction['explain_vi']), 540, 1330, '\fad(200,0)');
            $this->sfx('interface_pluck', $start + 600, 0.4);
            $this->cursor = $end;
        }

        if (filled($feedback['praise_vi'] ?? null)) {
            $start = $this->cursor;
            $this->text($start, $start + 3200, 'Big', $this->escape($feedback['praise_vi']), 540, 860, '\fad(200,200)');
            filled($feedback['next_vi'] ?? null) && $this->text($start, $start + 3200, 'Vi', $this->escape('Lần sau: '.$feedback['next_vi']), 540, 1150, '\fad(200,200)');
            $this->sfx('interface_confirmation', $start, 0.4);
            $this->cursor = $start + 3200;
        }
    }

    private function outro(): void
    {
        $start = $this->cursor;
        $end = $start + self::OUTRO_MS;
        $question = $this->lesson->kind === 'speaking' ? $this->lesson->content['prompt'] : ($this->lesson->content['question'] ?: 'What about you?');
        $this->text($start, $end, 'Label', 'YOUR TURN', 540, 560, '\fad(150,0)');
        $this->text($start, $end, 'Big', $this->escape($question), 540, 860, '\fad(200,0)');
        $this->text($start + 300, $end, 'Vi', 'Trả lời bằng tiếng Anh ở phần bình luận nhé!', 540, 1100, '\fad(200,0)');
        $next = $this->lesson->day < Curriculum::TOTAL_DAYS ? 'Day '.($this->lesson->day + 1).' ngày mai' : 'Hoàn thành 84 ngày!';
        $this->text($start + 600, $end, 'Small', "Follow để luyện nói cùng mình  ·  {$next}", 540, 1300, '\fad(200,0)');
        $this->sfx('jingles_pizzi_3', $start, 0.45);
        $this->cursor = $end;
    }

    /** Khoảng có tiếng nói trong bản thu (bỏ lặng đầu / cuối), ms. @return array{0: int, 1: int} */
    public static function speechSpan(EnglishRecording $recording): array
    {
        $segments = $recording->segments ?? [];
        if ($segments === []) {
            return [0, max(500, (int) $recording->duration_ms)];
        }
        $from = max(0, $segments[0]['start_ms'] - 150);
        $to = min(max($from + 300, (int) $recording->duration_ms ?: PHP_INT_MAX), end($segments)['end_ms'] + 300);

        return [$from, $to];
    }

    private function text(int $start, int $end, string $style, string $text, int $x, int $y, string $tags = ''): void
    {
        $y += self::Y_OFFSET;
        $this->events[] = [$start, $end, $style, "{\\an5\\pos({$x},{$y}){$tags}}{$text}"];
    }

    /** Chữ sáng dần theo giọng đọc (chia thời gian theo độ dài từng từ). */
    private function karaoke(int $start, int $end, string $sentence, int $speechMs, int $x, int $y, string $style = 'Big'): void
    {
        $words = preg_split('/\s+/', trim($sentence));
        $total = max(1, array_sum(array_map(fn ($w) => mb_strlen($w) + 2, $words)));
        $parts = array_map(fn ($w) => '{\kf'.max(1, (int) round($speechMs / 10 * (mb_strlen($w) + 2) / $total)).'}'.$this->escape($w).' ', $words);
        // Chữ chưa đọc màu trắng (\2c), đã đọc màu nổi (\1c).
        $y += self::Y_OFFSET;
        $highlight = $style === 'Good' ? '&H0080E04A&' : '&H0000D4FF&';
        $this->events[] = [$start, $end, $style, "{\\an5\\pos({$x},{$y})\\1c{$highlight}\\2c&HFFFFFF&}".rtrim(implode('', $parts))];
    }

    /** Câu với từ cần sửa tô đỏ, chưa rõ tô vàng. @param list<array{word: string, mark: string}> $words */
    private function marked(array $words): string
    {
        return implode(' ', array_map(fn ($w) => match ($w['mark']) {
            'wrong' => '{\c&H006B6BFF&\u1}'.$this->escape($w['word']).'{\c&HFFFFFF&\u0}',
            'unclear' => '{\c&H0000D4FF&}'.$this->escape($w['word']).'{\c&HFFFFFF&}',
            default => $this->escape($w['word']),
        }, $words));
    }

    private function clip(string $path, int $at, float $volume, ?int $from = null, ?int $to = null): void
    {
        $this->audio[] = ['path' => $path, 'at' => $at, 'volume' => $volume, 'from' => $from, 'to' => $to, 'voice' => true];
    }

    private function sfx(string $id, int $at, float $volume): void
    {
        $file = SoundLibrary::byId()[$id]['files'][0] ?? null;
        $file && $this->audio[] = ['path' => $file, 'at' => $at, 'volume' => $volume, 'from' => null, 'to' => null, 'voice' => false];
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', '{', '}', "\n"], ['', '(', ')', ' '], $text);
    }

    private function ass(): string
    {
        $style = fn (string $name, string $font, int $size, string $color, int $outline = 3, int $bold = 0) => "Style: {$name},{$font},{$size},{$color},&H00FFFFFF,&H64000000,&H96000000,{$bold},0,0,0,100,100,0,0,1,{$outline},2,5,90,90,0,1";
        $lines = [
            '[Script Info]', 'ScriptType: v4.00+', 'PlayResX: '.self::WIDTH, 'PlayResY: '.self::HEIGHT, 'WrapStyle: 0', 'ScaledBorderAndShadow: yes', '',
            '[V4+ Styles]',
            'Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding',
            $style('Day', 'Be Vietnam Pro', 190, '&H00FFFFFF', 6, 1),
            $style('Big', 'Be Vietnam Pro', 88, '&H00FFFFFF', 4, 1),
            $style('Good', 'Be Vietnam Pro', 82, '&H00FFFFFF', 4, 1),
            $style('Wrong', 'Be Vietnam Pro', 64, '&H006B6BFF', 3, 1),
            $style('Vi', 'Be Vietnam Pro', 56, '&H00F1F5F9', 3),
            $style('Ipa', 'DejaVu Sans', 56, '&H00FDE68A', 3),
            $style('Tip', 'Be Vietnam Pro', 46, '&H00E2E8F0', 3),
            $style('Label', 'Be Vietnam Pro', 54, '&H00FDE68A', 3, 1),
            $style('Score', 'Be Vietnam Pro', 150, '&H0080E04A', 6, 1),
            $style('Sub', 'Be Vietnam Pro', 66, '&H00FFFFFF', 4, 1),
            $style('Small', 'Be Vietnam Pro', 44, '&H00FFFFFF', 3, 1),
            '', '[Events]', 'Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text',
        ];
        foreach ($this->events as [$start, $end, $style, $text]) {
            $lines[] = 'Dialogue: 0,'.self::time($start).','.self::time($end).",{$style},,0,0,0,,{$text}";
        }

        return implode("\n", $lines)."\n";
    }

    /** @throws RuntimeException */
    private function encode(array $options): string
    {
        $total = $this->cursor;
        $seconds = self::seconds($total);
        File::put("{$this->dir}/text.ass", $this->ass());
        $palette = self::PALETTES[(Curriculum::day($this->lesson->day)['week'] - 1) % count(self::PALETTES)];
        $w = self::WIDTH;
        $h = self::HEIGHT;
        $args = ['-f', 'lavfi', '-i', "gradients=s={$w}x{$h}:c0={$palette[0]}:c1={$palette[1]}:x0=0:y0=0:x1={$w}:y1={$h}:speed=0.008:r=".self::FPS.":d={$seconds}"];
        $filters = ["[0:v]subtitles='".self::escapePath("{$this->dir}/text.ass")."':fontsdir='".self::escapePath(resource_path('fonts'))."',format=yuv420p[v]"];

        $voice = [];
        $sfx = [];
        foreach ($this->audio as $i => $clip) {
            $local = "{$this->dir}/a{$i}.".(pathinfo($clip['path'], PATHINFO_EXTENSION) ?: 'wav');
            File::put($local, (string) Media::disk()->get($clip['path']));
            $args = [...$args, '-i', $local];
            $trim = $clip['from'] !== null ? 'atrim='.self::seconds($clip['from']).':'.self::seconds((int) $clip['to']).',asetpts=PTS-STARTPTS,' : '';
            $filters[] = '['.($i + 1).":a]{$trim}aresample=44100,aformat=channel_layouts=stereo,volume={$clip['volume']},adelay={$clip['at']}:all=1[c{$i}]";
            $clip['voice'] ? $voice[] = "[c{$i}]" : $sfx[] = "[c{$i}]";
        }
        $silence = '[s]';
        $filters[] = "anullsrc=r=44100:cl=stereo,atrim=duration={$seconds}{$silence}";
        $filters[] = $silence.implode('', $voice).'amix=inputs='.(count($voice) + 1).':normalize=0:duration=first[voice]';
        $mix = ['[voice]'];
        $music = ($options['music_track_id'] ?? null) ? AudioTrack::where('user_id', $this->lesson->user_id)->find($options['music_track_id']) : null;
        if ($music) {
            $index = count($this->audio) + 1;
            $local = "{$this->dir}/music.".(pathinfo($music->path, PATHINFO_EXTENSION) ?: 'mp3');
            File::put($local, (string) Media::disk()->get($music->path));
            $args = [...$args, '-stream_loop', '-1', '-i', $local];
            $volume = max(0, min(40, (int) ($options['music_volume'] ?? 10))) / 100;
            $filters[count($filters) - 1] = str_replace('[voice]', '[voice0]', $filters[count($filters) - 1]);
            $filters[] = '[voice0]asplit=2[voice][key]';
            $filters[] = "[{$index}:a]atrim=0:{$seconds},asetpts=PTS-STARTPTS,aresample=44100,aformat=channel_layouts=stereo,volume={$volume},afade=t=out:st=".self::seconds(max(0, $total - 2000)).':d=2[m0]';
            $filters[] = '[m0][key]sidechaincompress=threshold=0.02:ratio=6:attack=20:release=400[music]';
            $mix[] = '[music]';
        }
        $mix = [...$mix, ...$sfx];
        $filters[] = implode('', $mix).'amix=inputs='.count($mix).':normalize=0:duration=first[a]';

        $out = "{$this->dir}/lesson.mp4";
        $result = Process::timeout(600)->run([
            config('videoaff.ffmpeg'), '-v', 'error', '-y', '-nostdin', ...$args, '-filter_complex', implode(';', $filters),
            '-map', '[v]', '-map', '[a]', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '21', '-r', (string) self::FPS,
            '-c:a', 'aac', '-b:a', '160k', '-t', $seconds, '-movflags', '+faststart', $out,
        ]);
        if ($result->failed()) {
            throw new RuntimeException('Dựng video lỗi: '.mb_substr(trim($result->errorOutput()), -400));
        }

        return $out;
    }

    private static function time(int $ms): string
    {
        return sprintf('%d:%02d:%02d.%02d', intdiv($ms, 3600000), intdiv($ms % 3600000, 60000), intdiv($ms % 60000, 1000), intdiv($ms % 1000, 10));
    }

    private static function seconds(int $ms): string
    {
        return rtrim(rtrim(number_format($ms / 1000, 3, '.', ''), '0'), '.') ?: '0';
    }

    private static function escapePath(string $path): string
    {
        return str_replace(['\\', "'", ':'], ['\\\\', "\\'", '\\:'], $path);
    }
}
