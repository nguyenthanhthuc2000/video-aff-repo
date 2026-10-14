<?php

namespace App\Services\Film;

use App\Services\Image\MuseTask;
use App\Services\Video\MuseBrowserClipGenerator;

/** Clip phim hoạt hình qua Muse: giữ đúng phong cách và khổ khung của khung hình (ngang hoặc dọc), không chữ, không nhạc. */
class FilmMuseClipGenerator extends MuseBrowserClipGenerator
{
    public const RULES = 'Animate the attached animation keyframe into a clip with exactly the same frame shape as the keyframe (horizontal or vertical, '
        .'as stated below). Keep exactly the same art style, colors, characters and background as the keyframe (it is animation, NOT live action; '
        .'no photorealism). No text, captions or watermarks.';

    public const LIVE_ACTION_RULES = 'Animate the attached film still into a clip with exactly the same frame shape as the still (horizontal or vertical, '
        .'as stated below). Keep exactly the same look, people, faces, outfits, colors, lighting and background as the still. No text, captions or watermarks.';

    public static function message(string $code, string $prompt): string
    {
        return MuseTask::header($code, 'video')."\n".(FilmStyle::liveAction($prompt) ? self::LIVE_ACTION_RULES : self::RULES)."\n\n{$prompt}";
    }
}
