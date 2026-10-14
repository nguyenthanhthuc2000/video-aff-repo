<?php

namespace App\Support;

/**
 * Tên nghệ sĩ / ban nhạc thật nổi tiếng (rock, metal, pop) không được dùng cho ban nhạc ảo, Shorts, MV: tránh mô phỏng người thật.
 * Chỉ giữ tên đặc trưng; bỏ tên trùng từ thông dụng (Queen, Kiss, Journey, Cream...) để không chặn nhầm.
 */
final class RealArtists
{
    public const NAMES = [
        'deep purple', 'black sabbath', 'ozzy', 'osbourne', 'led zeppelin', 'metallica', 'iron maiden', 'judas priest', 'motörhead', 'motorhead',
        'ac/dc', 'acdc', 'guns n roses', "guns n' roses", 'slash', 'axl rose', 'aerosmith', 'steven tyler', 'bon jovi', 'def leppard',
        'whitesnake', 'scorpions', 'van halen', 'pantera', 'slayer', 'megadeth', 'sepultura', 'soulfly', 'cavalera', 'slipknot', 'korn',
        'linkin park', 'nightwish', 'tarja', 'floor jansen', 'arch enemy', 'alissa white-gluz', 'angela gossow', 'dio', 'rainbow', 'manowar',
        'helloween', 'blind guardian', 'rammstein', 'system of a down', 'tool band', 'nirvana', 'kurt cobain', 'pearl jam', 'soundgarden',
        'alice in chains', 'foo fighters', 'dave grohl', 'red hot chili peppers', 'the beatles', 'beatles', 'rolling stones', 'mick jagger',
        'pink floyd', 'the who', 'jimi hendrix', 'hendrix', 'freddie mercury', 'brian may', 'david bowie', 'elvis', 'prince rogers',
        'michael jackson', 'madonna', 'lady gaga', 'taylor swift', 'beyonce', 'beyoncé', 'rihanna', 'bts', 'blackpink', 'ed sheeran',
        'billie eilish', 'the weeknd', 'eminem', 'drake', 'fleetwood mac', 'stevie nicks', 'lynyrd skynyrd', 'creedence', 'eagles band',
        'bon scott', 'angus young', 'lemmy', 'james hetfield', 'lars ulrich', 'bruce dickinson', 'rob halford', 'ronnie james dio',
        'sơn tùng', 'son tung', 'mỹ tâm', 'đen vâu', 'hoàng thùy linh',
    ];

    /** Tên thật đầu tiên có trong đoạn chữ (không phân biệt hoa thường, so nguyên từ). */
    public static function find(string $text): ?string
    {
        $text = mb_strtolower($text);
        foreach (self::NAMES as $name) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/u', $text)) {
                return $name;
            }
        }

        return null;
    }
}
