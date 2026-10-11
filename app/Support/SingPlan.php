<?php

namespace App\Support;

/**
 * Hát nhép theo phân tích giọng hát (vocals: khoảng có lời, words: lời kèm mốc, đơn vị giây trên cả bài):
 * chọn đoạn nhạc bắt đầu ở đầu một câu hát, cắt cảnh vào khoảng nghỉ giữa các câu, và viết dòng thời gian cho từng clip:
 * có lời thì nhép đúng chữ, không có lời thì ngậm miệng làm duyên (cười, nháy mắt...), không nhép bừa.
 */
final class SingPlan
{
    /** Hành động khi không có lời, đổi lần lượt để các khúc nhạc không lặp một kiểu. */
    public const IDLE_ACTIONS = [
        'lips closed, she smiles shyly at the camera and sways gently to the beat',
        'lips closed, she gives a playful wink and a cute head tilt',
        'lips closed, she tucks her hair behind her ear and smiles sweetly',
        'lips closed, she covers a little laugh with her hand and glances at the camera',
        'lips closed, she bobs her head to the rhythm with a confident smile',
        'lips closed, she blinks slowly and gives a soft charming smile',
    ];

    /** Khoảng nghỉ ngắn hơn mức này là lấy hơi giữa câu, không chèn hành động làm duyên. */
    private const BREATH = 0.8;

    /** Khe ngắn hơn mức này coi như cùng một câu. */
    private const MERGE = 0.3;

    /**
     * Giây bắt đầu của đoạn nhạc dài $seconds: ứng viên là đầu mỗi câu hát (lùi 0,4 giây), chọn đoạn có nhiều lời nhất,
     * hoà thì đoạn to (sôi động) hơn. $levels: độ lớn từng giây (dB) của cả bài.
     */
    public static function bestStart(array $analysis, array $levels, int $seconds): float
    {
        $duration = (float) ($analysis['duration'] ?? count($levels));
        if ($duration <= $seconds) {
            return 0.0;
        }

        $candidates = [0.0];
        foreach ($analysis['vocals'] ?? [] as $vocal) {
            $candidates[] = max(0.0, round($vocal['start'] - 0.4, 1));
        }

        $energy = array_map(fn (float $db) => 10 ** ($db / 10), $levels);
        $maxEnergy = max(1e-12, ...($energy ?: [0]));
        [$best, $bestScore] = [0.0, -1.0];
        foreach (array_unique($candidates) as $start) {
            if ($start + $seconds > $duration) {
                continue;
            }
            $sung = self::sungSeconds($analysis, $start, $start + $seconds) / $seconds;
            $window = array_slice($energy, (int) floor($start), $seconds);
            $loud = $window ? array_sum($window) / count($window) / $maxEnergy : 0;
            $score = $sung * 2 + $loud;
            if ($score > $bestScore + 0.01) {
                [$best, $bestScore] = [$start, $score];
            }
        }

        return $best;
    }

    /**
     * Dời chỗ cắt giữa các cảnh vào khoảng nghỉ gần nhất (trong 1,2 giây) để không cắt ngang câu hát; mỗi cảnh 3–8 giây.
     *
     * @param  list<int>  $durationsMs
     * @return list<int>
     */
    public static function retime(array $durationsMs, array $analysis, float $start): array
    {
        $gaps = self::gaps($analysis, $start, $start + array_sum($durationsMs) / 1000);
        $cuts = [];
        $at = 0.0;
        foreach (array_slice($durationsMs, 0, -1) as $ms) {
            $at += $ms / 1000;
            $previous = $cuts === [] ? 0.0 : end($cuts);
            // Điểm cắt gần nhất nằm trong một khoảng nghỉ (cách mép 0,15 giây).
            $best = null;
            foreach ($gaps as [$a, $b]) {
                $point = min(max($at, $a - $start + 0.15), $b - $start - 0.15);
                $length = $point - $previous;
                if (abs($point - $at) <= 1.2 && $length >= 3 && $length <= 8 && ($best === null || abs($point - $at) < abs($best - $at))) {
                    $best = $point;
                }
            }
            $cuts[] = $best ?? $at;
        }

        $total = array_sum($durationsMs) / 1000;
        $bounds = [0.0, ...$cuts, $total];
        $result = [];
        for ($i = 1; $i < count($bounds); $i++) {
            $result[] = (int) round(($bounds[$i] - $bounds[$i - 1]) * 1000);
        }

        // Cảnh cuối quá ngắn / dài thì giữ nguyên như kịch bản.
        $last = end($result);

        return $last >= 2000 && $last <= 10000 ? $result : $durationsMs;
    }

    /** Dòng thời gian cho prompt clip (giây tính từ đầu đoạn nhạc gửi kèm). */
    public static function timeline(array $analysis, float $start, float $length, int $offset = 0): string
    {
        $end = $start + $length;
        $parts = [];
        $cursor = $start;
        $idle = $offset;
        foreach (self::sungIntervals($analysis, $start, $end) as [$a, $b]) {
            if ($a - $cursor >= self::BREATH || ($parts === [] && $a - $cursor >= 0.3)) {
                $parts[] = self::span($cursor - $start, $a - $start).': no singing — '.self::IDLE_ACTIONS[$idle++ % count(self::IDLE_ACTIONS)];
            }
            $words = self::words($analysis, $a, $b);
            $parts[] = self::span($a - $start, $b - $start).': singing'
                .($words !== '' ? ' "'.$words.'" — lip-sync these words, mouth shapes clearly following each syllable' : ' — lip-sync the vocals, mouth moving with each syllable');
            $cursor = $b;
        }
        if ($end - $cursor >= 0.3) {
            $parts[] = self::span($cursor - $start, $length).': no singing — '.self::IDLE_ACTIONS[$idle % count(self::IDLE_ACTIONS)];
        }

        return 'Singing timeline of the attached audio, follow it exactly: '.implode('; ', $parts).'. '
            .'Move the lips ONLY during the singing parts; whenever there is no singing her mouth stays closed or smiling (no mouthing, no talking).';
    }

    /** @return list<array{0: float, 1: float}> khoảng có lời trong [from, to], đã nối các lần lấy hơi ngắn */
    private static function sungIntervals(array $analysis, float $from, float $to): array
    {
        $result = [];
        foreach ($analysis['vocals'] ?? [] as $vocal) {
            $a = max($from, (float) $vocal['start']);
            $b = min($to, (float) $vocal['end']);
            if ($b - $a < 0.2) {
                continue;
            }
            if ($result !== [] && $a - $result[count($result) - 1][1] < self::MERGE) {
                $result[count($result) - 1][1] = $b;
            } else {
                $result[] = [$a, $b];
            }
        }

        return $result;
    }

    private static function sungSeconds(array $analysis, float $from, float $to): float
    {
        return array_sum(array_map(fn ($interval) => $interval[1] - $interval[0], self::sungIntervals($analysis, $from, $to)));
    }

    /** @return list<array{0: float, 1: float}> khoảng nghỉ (theo giây trên cả bài) */
    private static function gaps(array $analysis, float $from, float $to): array
    {
        $gaps = [];
        $cursor = $from;
        foreach (self::sungIntervals($analysis, $from, $to) as [$a, $b]) {
            if ($a - $cursor >= 0.3) {
                $gaps[] = [$cursor, $a];
            }
            $cursor = $b;
        }
        if ($to - $cursor >= 0.3) {
            $gaps[] = [$cursor, $to];
        }

        return $gaps;
    }

    private static function words(array $analysis, float $from, float $to): string
    {
        $words = array_filter($analysis['words'] ?? [], fn ($word) => ($word['start'] + $word['end']) / 2 >= $from && ($word['start'] + $word['end']) / 2 < $to);

        return trim(preg_replace('/\s+/u', ' ', implode(' ', array_map(fn ($word) => str_replace('"', '', $word['text']), $words))));
    }

    private static function span(float $a, float $b): string
    {
        return number_format(max(0, $a), 1, '.', '').'–'.number_format($b, 1, '.', '').'s';
    }
}
