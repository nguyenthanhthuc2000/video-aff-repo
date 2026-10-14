<?php

namespace App\Services\Film;

use App\Models\Episode;
use App\Services\Script\ScriptWriterException;

/**
 * Claude sáng tác nhạc riêng cho tập: một nhạc hiệu (thẻ tiêu đề), một đoạn nhạc cho mỗi phân đoạn theo không khí của phân đoạn,
 * một đoạn kết. Claude viết giai điệu + hợp âm từng ô nhịp; dịch vụ audio (music.py) tự phối bass / pad / trống theo phong cách
 * và phát bằng SoundFont miễn phí, nên nhạc là của riêng tập, dùng thương mại được.
 */
final class ScoreComposer
{
    public const STYLES = ['playful', 'adventure', 'mystery', 'sneaky', 'tender', 'calm', 'tense', 'triumphant', 'funny'];

    public const INSTRUMENTS = [
        'piano', 'bright_piano', 'celesta', 'glockenspiel', 'music_box', 'vibraphone', 'marimba', 'xylophone', 'accordion', 'harmonica',
        'nylon_guitar', 'steel_guitar', 'ukulele', 'pizzicato_strings', 'harp', 'strings', 'slow_strings', 'choir_aahs', 'trumpet', 'trombone',
        'tuba', 'french_horn', 'brass', 'soprano_sax', 'clarinet', 'oboe', 'bassoon', 'flute', 'recorder', 'pan_flute', 'ocarina', 'warm_pad',
        'kalimba', 'synth_lead',
    ];

    public const SYSTEM = <<<'PROMPT'
    You are the composer of an original 2D animated TV series for kids. Write the music of ONE episode as short loopable cues.
    - The title cue is the series main theme: a catchy, simple, singable melody (a clear motif repeated and varied).
      The end cue is a gentle or happy variation of the same motif. Sequence cues match the mood of each sequence and may quote
      the motif; keep the same key family so the episode feels like one score.
    - Each cue: tempo (BPM), style (one of the allowed styles: it decides the accompaniment), lead / pad / arp instruments from
      the allowed list, and 4–8 bars of 4/4. Each bar: one chord symbol (C, Am, F, G7, Dm7, Bb, Ebmaj7, Fsus4...) and the melody of
      that bar as space-separated "Note:beats" tokens (for example "E5:1 G5:0.5 A5:0.5 C6:2"); use R for a rest (R:1).
      The beats of every bar add up to exactly 4. Melody range C4–C6, mostly stepwise with a few leaps, notes that fit the chord
      on strong beats. Under dialogue the music must stay light: calm sequence cues use fewer notes and longer values.
    - Kid-friendly and original: never copy existing songs or themes.
    PROMPT;

    /**
     * @return array{title: array, end: array, sequences: list<array{sequence: int, mood: string, cue: array}>}
     *
     * @throws ScriptWriterException
     */
    public static function compose(Episode $episode): array
    {
        $series = $episode->series;
        $outline = collect($episode->outline['sequences'] ?? []);
        $numbers = $episode->shots()->pluck('sequence')->unique()->sort()->values();
        $sequences = $numbers->map(fn ($n) => "Sequence {$n}: ".(($s = $outline[$n - 1] ?? null) ? "{$s['title']}: {$s['summary']}" : 'continues the story'))->implode("\n");
        $text = "Series: {$series->title}\nTone: ".($series->bible['tone'] ?? '')."\nEpisode {$episode->number}: {$episode->title}\n{$episode->synopsis}\n\n"
            ."Sequences:\n{$sequences}\n\nAllowed styles: ".implode(', ', self::STYLES)."\nAllowed instruments: ".implode(', ', self::INSTRUMENTS)
            ."\nWrite the title cue, one cue for each sequence above (use its number), and the end cue.";

        $cue = [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['tempo', 'style', 'lead', 'pad', 'arp', 'bars'],
            'properties' => [
                'tempo' => ['type' => 'integer'],
                'style' => ['type' => 'string', 'enum' => self::STYLES],
                'lead' => ['type' => 'string', 'enum' => self::INSTRUMENTS],
                'pad' => ['type' => 'string', 'enum' => self::INSTRUMENTS],
                'arp' => ['type' => 'string', 'enum' => self::INSTRUMENTS],
                'bars' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['chord', 'melody'],
                    'properties' => ['chord' => ['type' => 'string'], 'melody' => ['type' => 'string']]]],
            ],
        ];
        $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['title', 'end', 'sequences'], 'properties' => [
            'title' => $cue, 'end' => $cue,
            'sequences' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['sequence', 'mood', 'cue'],
                'properties' => ['sequence' => ['type' => 'integer'], 'mood' => ['type' => 'string'], 'cue' => $cue]]],
        ]];
        $data = FilmClaude::json(self::SYSTEM, $text, $schema, 'film_episode', "film_score:{$episode->id}", 12000, fn () => self::sample($numbers->all()));

        return self::normalize($data, $numbers->all());
    }

    /** Giữ cue hợp lệ: nhịp độ 50–170, phong cách / nhạc cụ trong danh sách, 1–16 ô nhịp; phân đoạn thiếu dùng nhạc hiệu. */
    public static function normalize(array $data, array $sequenceNumbers): array
    {
        $clean = function (?array $cue, string $fallbackStyle) {
            $bars = collect($cue['bars'] ?? [])->take(16)->map(fn ($b) => ['chord' => mb_substr(trim((string) ($b['chord'] ?? 'C')), 0, 12) ?: 'C', 'melody' => mb_substr(trim((string) ($b['melody'] ?? '')), 0, 300)])
                ->filter(fn ($b) => $b['melody'] !== '')->values()->all();

            return [
                'tempo' => max(50, min(170, (int) ($cue['tempo'] ?? 100))),
                'style' => in_array($cue['style'] ?? null, self::STYLES, true) ? $cue['style'] : $fallbackStyle,
                'lead' => in_array($cue['lead'] ?? null, self::INSTRUMENTS, true) ? $cue['lead'] : 'flute',
                'pad' => in_array($cue['pad'] ?? null, self::INSTRUMENTS, true) ? $cue['pad'] : 'strings',
                'arp' => in_array($cue['arp'] ?? null, self::INSTRUMENTS, true) ? $cue['arp'] : 'harp',
                'bars' => $bars ?: [['chord' => 'C', 'melody' => 'C5:1 E5:1 G5:2']],
            ];
        };
        $title = $clean($data['title'] ?? null, 'playful');
        $bySequence = collect($data['sequences'] ?? [])->keyBy(fn ($s) => (int) ($s['sequence'] ?? 0));

        return [
            'title' => $title,
            'end' => $clean($data['end'] ?? null, 'tender'),
            'sequences' => array_map(fn ($n) => [
                'sequence' => (int) $n,
                'mood' => mb_substr(trim((string) ($bySequence[$n]['mood'] ?? '')), 0, 80),
                'cue' => isset($bySequence[$n]) ? $clean($bySequence[$n]['cue'] ?? null, 'playful') : $title,
            ], array_values($sequenceNumbers)),
        ];
    }

    private static function sample(array $numbers): array
    {
        $cue = fn (string $style, int $tempo) => ['tempo' => $tempo, 'style' => $style, 'lead' => 'xylophone', 'pad' => 'strings', 'arp' => 'harp', 'bars' => [
            ['chord' => 'C', 'melody' => 'E5:0.5 G5:0.5 C6:1 G5:1 E5:1'], ['chord' => 'F', 'melody' => 'F5:1 A5:1 C6:2'],
            ['chord' => 'G7', 'melody' => 'G5:1 F5:1 D5:2'], ['chord' => 'C', 'melody' => 'C5:2 R:2'],
        ]];

        return [
            'title' => $cue('playful', 118), 'end' => $cue('tender', 84),
            'sequences' => array_map(fn ($n) => ['sequence' => $n, 'mood' => 'curious', 'cue' => $cue($n % 2 ? 'adventure' : 'mystery', 100)], $numbers),
        ];
    }
}
