<?php

namespace App\Support;

use Normalizer;

/**
 * Dóng lời bài hát chuẩn vào chữ Whisper nghe được (có mốc thời gian): Whisper hay nghe nhầm chữ khi hát nhưng mốc
 * thời gian đúng, lời chuẩn thì đúng chữ mà không có mốc. Căn hai chuỗi âm tiết (quy hoạch động kiểu Levenshtein,
 * trùng không dấu cũng tính là gần đúng), âm tiết lời chuẩn lấy mốc của chữ được căn, chữ không căn được thì chia đều.
 */
final class LyricsAligner
{
    /**
     * @param  list<array{start: float, end: float, text: string}>  $words
     * @return list<array{start: float, end: float, text: string}>
     */
    public static function align(array $words, string $lyrics): array
    {
        $syllables = self::syllables($lyrics);
        if ($syllables === [] || $words === []) {
            return $words;
        }

        $heard = array_map(fn (array $word) => mb_strtolower(self::syllables($word['text'])[0] ?? ''), $words);
        $wanted = array_map(fn (string $text) => mb_strtolower($text), $syllables);
        $n = count($heard);
        $m = count($wanted);

        // cost[i][j]: căn i chữ nghe được đầu với j âm tiết lời chuẩn đầu.
        $cost = array_fill(0, $n + 1, array_fill(0, $m + 1, 0.0));
        for ($i = 1; $i <= $n; $i++) {
            $cost[$i][0] = $i;
        }
        for ($j = 1; $j <= $m; $j++) {
            $cost[0][$j] = $j;
        }
        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                $cost[$i][$j] = min(
                    $cost[$i - 1][$j - 1] + self::distance($heard[$i - 1], $wanted[$j - 1]),
                    $cost[$i - 1][$j] + 1,
                    $cost[$i][$j - 1] + 1,
                );
            }
        }

        // Truy ngược: âm tiết j được căn với chữ i nào.
        $match = array_fill(0, $m, null);
        [$i, $j] = [$n, $m];
        while ($i > 0 && $j > 0) {
            if (abs($cost[$i][$j] - ($cost[$i - 1][$j - 1] + self::distance($heard[$i - 1], $wanted[$j - 1]))) < 1e-9) {
                $match[$j - 1] = $i - 1;
                [$i, $j] = [$i - 1, $j - 1];
            } elseif (abs($cost[$i][$j] - ($cost[$i - 1][$j] + 1)) < 1e-9) {
                $i--;
            } else {
                $j--;
            }
        }

        // Âm tiết không căn được: chia đều khoảng giữa hai âm tiết đã có mốc (đầu / cuối bài thì bỏ).
        $timed = [];
        $k = 0;
        while ($k < $m) {
            if ($match[$k] !== null) {
                $timed[] = ['start' => $words[$match[$k]]['start'], 'end' => $words[$match[$k]]['end'], 'text' => $syllables[$k]];
                $k++;

                continue;
            }
            $run = [];
            while ($k < $m && $match[$k] === null) {
                $run[] = $syllables[$k++];
            }
            $before = $timed === [] ? null : end($timed)['end'];
            $after = $k < $m ? $words[$match[$k]]['start'] : null;
            if ($before === null || $after === null || $after - $before > 4) {
                continue;
            }
            if ($after - $before < 0.05 * count($run)) {
                // Không còn chỗ trống: chia thời gian của chữ đứng trước cho nó và các âm tiết này.
                $previous = array_pop($timed);
                $step = ($previous['end'] - $previous['start']) / (count($run) + 1);
                foreach ([$previous['text'], ...$run] as $r => $text) {
                    $timed[] = ['start' => round($previous['start'] + $r * $step, 2), 'end' => round($previous['start'] + ($r + 1) * $step, 2), 'text' => $text];
                }

                continue;
            }
            $step = ($after - $before) / count($run);
            foreach ($run as $r => $text) {
                $timed[] = ['start' => round($before + $r * $step, 2), 'end' => round($before + ($r + 1) * $step, 2), 'text' => $text];
            }
        }

        return $timed;
    }

    /** @return list<string> âm tiết (tiếng Việt: mỗi chữ cách nhau là một âm tiết), bỏ dấu câu */
    public static function syllables(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', Normalizer::normalize($text, Normalizer::FORM_C) ?: $text, $m);

        return $m[0];
    }

    /** 0 = trùng, 0,4 = trùng khi bỏ dấu, 0,5–1 = khác (gần giống chữ không dấu thì nhỏ hơn, ví dụ "dự" / "dựa"). */
    private static function distance(string $a, string $b): float
    {
        if ($a === $b) {
            return 0.0;
        }
        [$x, $y] = [self::plain($a), self::plain($b)];
        if ($x === $y) {
            return 0.4;
        }

        return min(1.0, 0.5 + 0.5 * levenshtein($x, $y) / max(1, strlen($x), strlen($y)));
    }

    private static function plain(string $text): string
    {
        $decomposed = Normalizer::normalize($text, Normalizer::FORM_D) ?: $text;

        return str_replace('đ', 'd', preg_replace('/\p{Mn}/u', '', $decomposed));
    }
}
