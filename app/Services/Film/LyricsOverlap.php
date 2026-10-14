<?php

namespace App\Services\Film;

/**
 * Kiểm tra lời mới có chép / gần chép lời bài tham khảo không: so cụm 4 chữ liên tiếp (bỏ dấu câu, chữ thường, bỏ dòng [Verse]...).
 * score: tỉ lệ cụm 4 chữ của lời mới có trong lời tham khảo; lines: câu mới mà quá nửa cụm 4 chữ trùng (hoặc trùng hẳn một câu).
 */
final class LyricsOverlap
{
    public const N = 4;

    /** Vượt mức này thì Claude viết lại (một lần), sau đó cảnh báo. */
    public const MAX_SCORE = 0.08;

    /** @return array{score: float, lines: list<string>} */
    public static function check(string $lyrics, string $reference): array
    {
        $referenceGrams = [];
        $referenceLines = [];
        foreach (self::lines($reference) as $words) {
            $referenceLines[implode(' ', $words)] = true;
            foreach (self::grams($words) as $gram) {
                $referenceGrams[$gram] = true;
            }
        }
        $total = 0;
        $hits = 0;
        $flagged = [];
        foreach (preg_split('/\R/u', $lyrics) ?: [] as $raw) {
            $words = self::words($raw);
            if ($words === []) {
                continue;
            }
            $grams = self::grams($words);
            $same = count(array_filter($grams, fn ($gram) => isset($referenceGrams[$gram])));
            $total += count($grams);
            $hits += $same;
            if (isset($referenceLines[implode(' ', $words)]) && count($words) >= 3 || ($grams !== [] && $same / count($grams) > 0.5)) {
                $flagged[] = trim($raw);
            }
        }

        return ['score' => $total > 0 ? round($hits / $total, 3) : 0.0, 'lines' => array_values(array_unique($flagged))];
    }

    public static function tooSimilar(array $result): bool
    {
        return $result['score'] > self::MAX_SCORE || $result['lines'] !== [];
    }

    /** @return list<list<string>> */
    private static function lines(string $text): array
    {
        return array_values(array_filter(array_map(self::words(...), preg_split('/\R/u', $text) ?: [])));
    }

    /** @return list<string> */
    private static function words(string $line): array
    {
        $line = trim($line);
        if ($line === '' || preg_match('/^\[.*\]$/u', $line)) {
            return [];
        }
        $clean = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', mb_strtolower($line));

        return preg_split('/\s+/u', trim((string) $clean), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** @param list<string> $words @return list<string> */
    private static function grams(array $words): array
    {
        if (count($words) < self::N) {
            return [];
        }

        return array_map(fn ($i) => implode(' ', array_slice($words, $i, self::N)), range(0, count($words) - self::N));
    }
}
