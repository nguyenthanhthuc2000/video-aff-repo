<?php

namespace App\Services\Film;

/**
 * Quy tắc nội dung theo độ tuổi người xem (docs/phim-hoat-hinh.md mục 2.7).
 * Giới hạn nội dung theo nhóm của tuổi nhỏ nhất (an toàn cho người xem nhỏ nhất); nhịp kể và từ vựng theo tuổi giữa khoảng.
 */
final class AgeRules
{
    private const GROUPS = [
        3 => [
            'story' => 'slow pace, repetition, one simple situation per episode; teach colors, numbers, letters, good habits',
            'english' => 'very simple words, sentences of 3–6 words, repeat key words',
            'limits' => 'no tension or scary moments, no frightening villains',
        ],
        6 => [
            'story' => 'one complete story per episode: humor, friendship, light adventure; the lesson is shown through actions',
            'english' => 'short sentences, common words, a few new words explained in context',
            'limits' => 'only mild tension, always a happy ending',
        ],
        9 => [
            'story' => 'layered plots, light mysteries and detective cases, fun science, story arcs across episodes',
            'english' => 'longer sentences, richer vocabulary, some wordplay',
            'limits' => 'moderate tension (mysteries, challenges) is fine; no bloody violence, no horror',
        ],
        13 => [
            'story' => 'teen-drama style plots with deeper emotions',
            'english' => 'natural, like regular TV shows',
            'limits' => 'follow YouTube community guidelines for all ages',
        ],
    ];

    public static function group(int $age): array
    {
        $key = collect(array_keys(self::GROUPS))->filter(fn (int $start) => $age >= $start)->last() ?? 3;

        return self::GROUPS[$key];
    }

    /** Đoạn hướng dẫn cho Claude theo khoảng tuổi. */
    public static function prompt(int $ageMin, int $ageMax): string
    {
        $middle = self::group(intdiv($ageMin + $ageMax, 2));
        $youngest = self::group($ageMin);

        return "Audience: children aged {$ageMin}–{$ageMax}.\n"
            ."- Storytelling: {$middle['story']}.\n"
            ."- English level: {$middle['english']}.\n"
            ."- Content limits (for the youngest viewers): {$youngest['limits']}.\n"
            .'- Always: main characters are the same age as the viewers or a little older; no mocking of appearance, '
            .'no dangerous behavior children could copy, no product placement or advertising.';
    }
}
