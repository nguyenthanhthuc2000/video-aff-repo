<?php

namespace App\Services\Film;

use App\Services\Image\MuseBrowserImageGenerator;
use App\Services\Image\MuseTask;

/**
 * Ảnh phim hoạt hình qua Muse: lời nhắn riêng (2D, khổ ngang 16:9, không phải ảnh chụp, không chữ).
 * Thử nghiệm F0: lời nhắn ảnh thật dọc 9:16 của video affiliate làm Muse vẽ ra người thật và ảnh dọc.
 */
class FilmMuseImageGenerator extends MuseBrowserImageGenerator
{
    public const RULES = 'Create one 2D cartoon illustration in a WIDE HORIZONTAL 16:9 landscape frame: a drawn animation frame, NOT a photo, '
        .'no photorealistic or real people. No text, letters, titles, captions or watermarks anywhere in the image.';

    public static function message(string $code, string $prompt): string
    {
        return MuseTask::header($code, 'image')."\n".self::RULES."\n\n{$prompt}";
    }
}
