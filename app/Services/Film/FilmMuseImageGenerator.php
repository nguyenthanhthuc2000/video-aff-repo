<?php

namespace App\Services\Film;

use App\Services\Image\MuseBrowserImageGenerator;
use App\Services\Image\MuseTask;

/**
 * Ảnh phim hoạt hình qua Muse: lời nhắn riêng (khung và phong cách 2D / 3D ghi trong prompt, không phải ảnh chụp, không chữ).
 * Thử nghiệm F0: lời nhắn ảnh thật dọc 9:16 của video affiliate làm Muse vẽ ra người thật và ảnh dọc.
 */
class FilmMuseImageGenerator extends MuseBrowserImageGenerator
{
    public const RULES = 'Create one image of an animated film in exactly the frame shape and the art style given below: an animation frame '
        .'(drawn or stylized 3D), NOT a photo, no photorealistic or real people. No text, letters, titles, captions or watermarks anywhere in the image.';

    /** Phong cách người thật (MV điện ảnh): nhân vật hư cấu, không phải người nổi tiếng. */
    public const LIVE_ACTION_RULES = 'Create one cinematic film still in exactly the frame shape and the visual style given below. The people are fictional '
        .'characters (not real celebrities). No text, letters, titles, captions, logos or watermarks anywhere in the image.';

    public static function message(string $code, string $prompt): string
    {
        return MuseTask::header($code, 'image')."\n".(FilmStyle::liveAction($prompt) ? self::LIVE_ACTION_RULES : self::RULES)."\n\n{$prompt}";
    }
}
