<?php

namespace App\Services\Film;

/** Phim / MV phong cách người thật (câu style có "live-action", "photorealistic"...): lời nhắn Muse không được ép nét vẽ hoạt hình. */
final class FilmStyle
{
    public static function liveAction(string $text): bool
    {
        return (bool) preg_match('/\b(live[- ]action|photo-?realistic|photoreal)\b/i', $text);
    }
}
