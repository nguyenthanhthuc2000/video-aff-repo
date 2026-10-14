<?php

namespace App\Services\English;

/**
 * Chấm phát âm một câu: so lời Whisper nghe được với câu mẫu (gióng từng từ, chấp nhận khác dấu câu / hoa thường / số viết bằng chữ).
 * Từ khớp mà Whisper không chắc (độ tin cậy thấp) là "chưa rõ"; từ thiếu hoặc nghe thành từ khác là "cần sửa".
 * Điểm 0–10 = (từ đúng + nửa từ chưa rõ) / số từ của câu mẫu.
 */
final class Pronunciation
{
    public const CLEAR = 0.45;

    /** @return list<string> */
    public static function tokens(string $text): array
    {
        $text = mb_strtolower(str_replace(['’', '‘'], "'", $text));
        $text = preg_replace("/[^\\p{L}\\p{N}' ]+/u", ' ', $text);

        return array_values(array_filter(array_map(fn ($w) => trim($w, "'"), preg_split('/\s+/', (string) $text)), fn ($w) => $w !== ''));
    }

    /**
     * @param  list<array{word: string, probability: float}>  $heard
     * @return array{score: int, words: list<array{word: string, mark: string}>} mark: ok | unclear | wrong
     */
    public static function score(string $expected, array $heard): array
    {
        $display = preg_split('/\s+/', trim($expected));
        $target = array_map(fn ($w) => self::tokens($w)[0] ?? '', $display);
        $spoken = [];
        foreach ($heard as $w) {
            foreach (self::tokens($w['word']) as $token) {
                $spoken[] = ['token' => $token, 'probability' => (float) $w['probability']];
            }
        }

        // Gióng hai dãy từ (LCS) để biết từ nào của câu mẫu đã được nói.
        $n = count($target);
        $m = count($spoken);
        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $dp[$i][$j] = self::same($target[$i], $spoken[$j]['token']) ? $dp[$i + 1][$j + 1] + 1 : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }
        $marks = array_fill(0, $n, 'wrong');
        for ($i = 0, $j = 0; $i < $n && $j < $m;) {
            if (self::same($target[$i], $spoken[$j]['token'])) {
                $marks[$i] = $spoken[$j]['probability'] >= self::CLEAR ? 'ok' : 'unclear';
                $i++;
                $j++;
            } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $i++;
            } else {
                $j++;
            }
        }
        $counted = array_filter(array_keys($target), fn ($i) => $target[$i] !== '');
        $points = array_sum(array_map(fn ($i) => ['ok' => 1, 'unclear' => 0.5, 'wrong' => 0][$marks[$i]], $counted));

        return [
            'score' => $counted === [] ? 0 : (int) round(10 * $points / count($counted)),
            'words' => array_map(fn ($word, $i) => ['word' => $word, 'mark' => $target[$i] === '' ? 'ok' : $marks[$i]], $display, array_keys($display)),
        ];
    }

    private static function same(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }
        $numbers = ['1' => 'one', '2' => 'two', '3' => 'three', '4' => 'four', '5' => 'five', '6' => 'six', '7' => 'seven', '8' => 'eight', '9' => 'nine', '10' => 'ten'];
        $a = $numbers[$a] ?? $a;
        $b = $numbers[$b] ?? $b;

        return $a === $b || (mb_strlen($a) > 4 && levenshtein($a, $b) === 1) || str_replace("'", '', $a) === str_replace("'", '', $b);
    }
}
