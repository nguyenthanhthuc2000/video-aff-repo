<?php

namespace App\Services\Film;

use App\Services\Image\MuseTask;
use App\Services\Video\MuseBrowserClipGenerator;

/** Clip phim hoạt hình qua Muse: giữ nét vẽ 2D của khung hình, khổ ngang 16:9, không chữ, không nhạc. */
class FilmMuseClipGenerator extends MuseBrowserClipGenerator
{
    public const RULES = 'Animate the attached 2D cartoon keyframe into a WIDE HORIZONTAL 16:9 animated TV clip. Keep exactly the same drawing style, '
        .'colors, characters and background as the keyframe (it is a cartoon, NOT live action; no photorealism). No text, captions or watermarks.';

    public static function message(string $code, string $prompt): string
    {
        return MuseTask::header($code, 'video')."\n".self::RULES."\n\n{$prompt}";
    }
}
