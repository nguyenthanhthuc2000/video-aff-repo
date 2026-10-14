<?php

namespace App\Services\Film;

use App\Models\Series;

/**
 * Danh sách tập người dùng có sẵn: mỗi dòng một tập "Tên tập | ý tưởng | thể loại", hoặc dán thẳng bảng markdown
 * ("| 01 | The Last Cookie | Milo chia bánh... | Cảm động |"). Dòng tiêu đề / dòng kẻ của bảng bị bỏ qua.
 */
final class EpisodeList
{
    public const MAX = 52;

    /** Tên thể loại (tiếng Việt / tiếng Anh) → khoá trong Series::KINDS. */
    private const KIND_WORDS = [
        'cảm động' => 'heartwarming', 'heartwarming' => 'heartwarming', 'wholesome' => 'heartwarming', 'ấm áp' => 'heartwarming',
        'hài hước' => 'funny', 'hài' => 'funny', 'funny' => 'funny', 'comedy' => 'funny',
        'kỳ diệu' => 'magical', 'kì diệu' => 'magical', 'magical' => 'magical', 'magic' => 'magical',
        'phiêu lưu' => 'adventure', 'adventure' => 'adventure',
    ];

    /** @return list<array{title: string, idea: string, kind: ?string}> */
    public static function parse(?string $text): array
    {
        $episodes = [];
        foreach (preg_split('/\R/u', (string) $text) as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^\|?\s*:?-{2,}/u', $line)) {
                continue;
            }
            $cells = str_contains($line, '|')
                ? array_values(array_filter(array_map('trim', explode('|', trim($line, " |\t"))), fn ($c) => $c !== ''))
                : array_map('trim', preg_split('/\s+[—–-]\s+/u', $line, 2));
            // Cột số thứ tự ở đầu bảng.
            if (isset($cells[0]) && preg_match('/^#?\d{1,3}\.?$/u', $cells[0])) {
                array_shift($cells);
            }
            // Dòng tiêu đề của bảng (# | Tựa đề | Ý tưởng | Thể loại).
            if ($cells === [] || $cells[0] === '#' || preg_match('/^(tựa đề|tên tập|title)/iu', $cells[0])) {
                continue;
            }
            $title = mb_substr(trim(preg_replace('/^[-*\d.\s]+(?=\p{L})/u', '', $cells[0]), " *_\t"), 0, 200);
            if ($title === '') {
                continue;
            }
            $episodes[] = [
                'title' => $title,
                'idea' => mb_substr(trim($cells[1] ?? ''), 0, 1000),
                'kind' => self::kind($cells[2] ?? null),
            ];
            if (count($episodes) >= self::MAX) {
                break;
            }
        }

        return $episodes;
    }

    public static function kind(?string $word): ?string
    {
        $word = mb_strtolower(trim((string) $word));
        if (isset(Series::KINDS[$word])) {
            return $word;
        }

        return self::KIND_WORDS[$word] ?? null;
    }
}
