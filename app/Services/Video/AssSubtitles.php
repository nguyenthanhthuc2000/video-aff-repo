<?php

namespace App\Services\Video;

/** Tạo file phụ đề .ass (chữ trên màn + lời đọc) từ thời lượng các cảnh. */
class AssSubtitles
{
    public const FONT = 'Be Vietnam Pro';

    public static function build(RenderSpec $spec, int $width = 1080, int $height = 1920): string
    {
        $font = self::FONT;
        $lines = [
            '[Script Info]',
            'ScriptType: v4.00+',
            "PlayResX: {$width}",
            "PlayResY: {$height}",
            'WrapStyle: 0',
            'ScaledBorderAndShadow: yes',
            '',
            '[V4+ Styles]',
            'Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding',
            // Màu ASS: &HAABBGGRR.
            // Hook, tiêu đề và lời đọc xếp ở phần dưới (canh đáy) để không che mặt người mẫu ở phần trên;
            // chừa ~400px đáy cho caption/nút của TikTok, Shopee Video và lề phải cho cột nút.
            "Style: Hook,{$font},80,&H00FFFFFF,&H00FFFFFF,&H00000000,&H64000000,-1,0,0,0,100,100,0,0,1,7,2,2,80,140,600,1",
            "Style: Title,{$font},66,&H00FFFFFF,&H00FFFFFF,&H00000000,&H96000000,-1,0,0,0,100,100,0,0,3,16,0,2,90,140,600,1",
            "Style: Sub,{$font},56,&H00FFFFFF,&H00FFFFFF,&H00000000,&H64000000,-1,0,0,0,100,100,0,0,1,5,1,2,90,140,400,1",
            "Style: End,{$font},84,&H0000E5FF,&H00FFFFFF,&H00000000,&H64000000,-1,0,0,0,100,100,0,0,1,7,2,5,90,90,0,1",
            "Style: EndSub,{$font},62,&H00FFFFFF,&H00FFFFFF,&H00000000,&H64000000,-1,0,0,0,100,100,0,0,1,5,1,2,90,90,640,1",
            '',
            '[Events]',
            'Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text',
        ];

        if (! $spec->withText) {
            return implode("\n", $lines)."\n";
        }

        $start = 0;
        foreach ($spec->scenes as $i => $scene) {
            $end = $start + $scene->durationMs;
            // Cảnh đầu hiện hook thay cho chữ trên màn của cảnh.
            $title = $i === 0 && $spec->hook !== '' ? $spec->hook : $scene->title;
            $style = $i === 0 && $spec->hook !== '' ? 'Hook' : 'Title';

            if ($title !== '') {
                $lines[] = self::dialogue($start, $end, $style, $title, '{\fad(150,150)}');
            }
            if ($scene->subtitle !== '') {
                $lines[] = self::dialogue($start, $end, 'Sub', $scene->subtitle);
            }
            $start = $end;
        }

        if ($spec->hasEndCard()) {
            $end = $start + $spec->endCardMs;
            $lines[] = self::dialogue($start, $end, 'End', $spec->endCardTitle, '{\fad(200,0)}');
            if ($spec->endCardSubtitle !== '') {
                $lines[] = self::dialogue($start, $end, 'EndSub', $spec->endCardSubtitle, '{\fad(200,0)}');
            }
        }

        return implode("\n", $lines)."\n";
    }

    private static function dialogue(int $startMs, int $endMs, string $style, string $text, string $prefix = ''): string
    {
        return sprintf('Dialogue: 0,%s,%s,%s,,0,0,0,,%s%s', self::time($startMs), self::time($endMs), $style, $prefix, self::escape($text));
    }

    public static function time(int $ms): string
    {
        $cs = intdiv($ms, 10);

        return sprintf('%d:%02d:%02d.%02d', intdiv($cs, 360000), intdiv($cs, 6000) % 60, intdiv($cs, 100) % 60, $cs % 100);
    }

    public static function escape(string $text): string
    {
        $text = str_replace(['\\', '{', '}'], ['＼', '(', ')'], trim($text));

        return preg_replace('/\R/u', '\N', $text);
    }
}
