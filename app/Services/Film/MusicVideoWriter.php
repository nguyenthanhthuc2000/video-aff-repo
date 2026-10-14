<?php

namespace App\Services\Film;

use App\Models\Episode;
use App\Models\Series;
use App\Models\SeriesCharacter;
use App\Models\SeriesLocation;
use App\Services\Script\ScriptWriterException;

/**
 * Claude viết phân cảnh MV ca nhạc trong một lượt: mỗi shot bắt đầu ở một mốc của bài hát (giây), có câu hát của đoạn đó,
 * hát nhép hay không, góc máy, hành động, chuyển động. Mốc lấy theo lời có thời gian (dịch vụ audio phân tích bài hát) nếu có.
 * Sau đó app nắn lại: shot nối liền, phủ đúng đoạn bài hát đã chọn, mỗi shot 2–10 giây (Muse làm clip tối đa 10 giây).
 */
final class MusicVideoWriter
{
    public const MIN_SECONDS = 2;

    public const MAX_SECONDS = 10;

    public const SYSTEM = <<<'PROMPT'
    You are the director of an original official music video made entirely with AI image and image-to-video tools.
    Write the complete shot list of the music video as JSON, cut exactly to the song.

    Rules:
    - Every shot has "start": the second in the song where the shot begins (relative to the start of the song excerpt given below).
      The first shot starts at 0; shots are in order; the last shot runs until the end of the excerpt. Cut on the beat and on lyric lines:
      a new lyric line or section is a good place to cut. Shots last 2–10 seconds (most 3–6 s; slower in verses, faster in the chorus).
    - "lyric": the exact lyric sung during the shot (copy from the timed lyrics, empty for instrumental parts).
    - "lip_sync": true when the singer is on screen, facing the camera enough to see the mouth, and sings in this shot. Mix performance
      shots (lip_sync) with story shots and b-roll (no lip sync). Use lip sync for most of the chorus and some verse lines; never in instrumental parts.
    - Tell a visual story that follows the meaning and emotion of the lyrics: intro, verses, chorus (bigger, more energy), bridge, final chorus, outro.
      Keep a clear arc and recurring visual motifs; the chorus can return to the same performance setup.
    - "characters": names from the character list only, at most 2 per shot. "location": a name from the location list.
    - "action": what we see in the first frame (subject, pose, expression, composition, lighting) in one or two sentences.
    - "motion": what moves during the clip (one main action, camera move, hair / cloth / light), one or two sentences.
    - camera_move: static, push_in, pull_out, pan_left, pan_right, tilt_up or tilt_down.
    - No text, logos or brand names in images. Original work only.
    - title: the video title (song title + "Official Music Video" is fine). logline: one sentence about the story of the video.
    - continuity: short lines that must stay identical in every shot (outfits, props, colors, time of day per location).
    PROMPT;

    public static function schema(): array
    {
        $string = ['type' => 'string'];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['title', 'logline', 'continuity', 'shots'],
            'properties' => [
                'title' => $string,
                'logline' => $string,
                'continuity' => ['type' => 'array', 'items' => $string],
                'shots' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['start', 'section', 'lyric', 'lip_sync', 'characters', 'location', 'camera', 'camera_move', 'action', 'motion'],
                    'properties' => [
                        'start' => ['type' => 'number'],
                        'section' => $string,
                        'lyric' => $string,
                        'lip_sync' => ['type' => 'boolean'],
                        'characters' => ['type' => 'array', 'items' => $string],
                        'location' => $string,
                        'camera' => ['type' => 'string', 'enum' => EpisodeWriter::CAMERAS],
                        'camera_move' => ['type' => 'string', 'enum' => array_keys(EpisodeWriter::CAMERA_MOVES)],
                        'action' => $string,
                        'motion' => $string,
                    ],
                ]],
            ],
        ];
    }

    /**
     * Lời bài hát có mốc thời gian trong đoạn đã chọn (giây tính từ đầu đoạn), gom từng từ thành câu theo chỗ ngắt hơi.
     *
     * @return list<array{start: float, end: float, text: string}>
     */
    public static function timedLyrics(Series $series): array
    {
        $words = $series->song?->vocal_analysis['words'] ?? [];
        [$from, $to] = $series->songRange();
        $lines = [];
        $current = null;
        foreach ($words as $word) {
            $start = (float) ($word['start'] ?? 0) - $from / 1000;
            $end = (float) ($word['end'] ?? 0) - $from / 1000;
            $text = trim((string) ($word['text'] ?? ''));
            if ($text === '' || $start < 0 || $start >= ($to - $from) / 1000) {
                continue;
            }
            if ($current !== null && ($start - $current['end'] > 0.7 || str_word_count($current['text']) >= 12)) {
                $lines[] = $current;
                $current = null;
            }
            $current = $current === null
                ? ['start' => round($start, 2), 'end' => round($end, 2), 'text' => $text]
                : [...$current, 'end' => round($end, 2), 'text' => "{$current['text']} {$text}"];
        }
        if ($current !== null) {
            $lines[] = $current;
        }

        return $lines;
    }

    public static function context(Episode $episode): string
    {
        $series = $episode->series;
        $bible = $series->bible ?? [];
        [$from, $to] = $series->songRange();
        $seconds = round(($to - $from) / 1000, 1);
        $characters = EpisodeWriter::characters($episode)->map(fn (SeriesCharacter $c) => "- {$c->name} ({$c->role}): {$c->appearance}")->implode("\n");
        $locations = $series->locations()->get()->map(fn (SeriesLocation $l) => "- {$l->name}: {$l->description}")->implode("\n");
        $timed = collect(self::timedLyrics($series))->map(fn ($l) => sprintf('[%.1f–%.1f] %s', $l['start'], $l['end'], $l['text']))->implode("\n");

        return 'Song: '.($series->song?->name ?? $series->title)."\n"
            ."Excerpt used: {$seconds} seconds".($from > 0 ? ' (starting at '.round($from / 1000, 1).'s of the song)' : '').".\n"
            .(isset($bible['logline']) ? "Concept: {$bible['logline']}\n" : '')
            .(isset($bible['premise']) ? "Story: {$bible['premise']}\n" : '')
            .(isset($bible['tone']) ? "Mood: {$bible['tone']}\n" : '')
            ."Frame: {$series->frameLine()}. Art style: {$series->style}.\n\n"
            ."Characters (the singer is the main one):\n{$characters}\n\n"
            .($locations !== '' ? "Locations:\n{$locations}\n\n" : '')
            .($timed !== '' ? "Timed lyrics (seconds from the start of the excerpt; detected automatically, may contain small errors):\n{$timed}\n\n" : '')
            .(filled($series->lyrics) ? "Official lyrics from the artist (use this spelling):\n{$series->lyrics}\n\n" : '')
            .($timed === '' ? "No timing is available: spread the lyric lines evenly over the excerpt with an intro and an outro.\n\n" : '')
            .match ($series->lipSyncMode()) {
                'full' => 'Lip sync: the whole song. Whenever there are vocals, prefer shots where the singer sings to the camera (lip_sync true); '
                    ."b-roll and story shots only between lines or in instrumental parts.\n\n",
                'none' => "Lip sync: none. lip_sync is always false; nobody sings on camera.\n\n",
                default => 'Lip sync: chorus only. lip_sync can be true only in chorus shots (section "chorus"); verses, bridge, intro and outro '
                    ."are story shots and b-roll without lip sync.\n\n",
            }
        .(filled($episode->brief) ? "The creator's own treatment (may be in Vietnamese; follow it closely, write in English):\n{$episode->brief}\n\n" : '')
        .'Allowed camera values: '.implode(', ', EpisodeWriter::CAMERAS).".\n"
        ."Write the shot list for the whole {$seconds}-second excerpt.";
    }

    /**
     * @return array{shots: list<array>, package: array}
     *
     * @throws ScriptWriterException
     */
    public static function write(Episode $episode): array
    {
        $data = FilmClaude::json(self::SYSTEM, self::context($episode), self::schema(), 'film_episode', "film_mv:{$episode->id}", 16000, fn () => self::sample($episode));

        return self::normalize($episode, (array) $data);
    }

    /**
     * Nắn phân cảnh theo bài hát: sắp theo mốc, shot đầu ở 0, shot nối liền tới hết đoạn, shot quá ngắn gộp vào shot trước,
     * shot quá dài chia thành nhiều shot nối nhau (giữ khung cuối làm khung đầu).
     *
     * @return array{shots: list<array>, package: array}
     *
     * @throws ScriptWriterException
     */
    public static function normalize(Episode $episode, array $data): array
    {
        $series = $episode->series;
        [$from, $to] = $series->songRange();
        $total = $to - $from;
        $raw = collect((array) ($data['shots'] ?? []))->filter(fn ($s) => is_array($s) && trim((string) ($s['action'] ?? '')) !== '')
            ->map(fn ($s) => [...$s, 'start_ms' => (int) round(max(0, (float) ($s['start'] ?? 0)) * 1000)])
            ->filter(fn ($s) => $s['start_ms'] < $total - self::MIN_SECONDS * 500)
            ->sortBy('start_ms')->values()->take(120)->all();
        if ($raw === []) {
            throw new ScriptWriterException('Claude không trả về shot nào cho MV.');
        }
        $raw[0]['start_ms'] = 0;

        // Bỏ shot bắt đầu quá sát shot trước (gộp vào shot trước).
        $kept = [];
        foreach ($raw as $shot) {
            if ($kept !== [] && self::MIN_SECONDS * 1000 > $shot['start_ms'] - end($kept)['start_ms']) {
                continue;
            }
            $kept[] = $shot;
        }
        // Shot cuối ngắn quá: gộp vào shot trước.
        if (count($kept) > 1 && $total - end($kept)['start_ms'] < self::MIN_SECONDS * 1000) {
            array_pop($kept);
        }

        $base = EpisodeWriter::normalizeShots($episode, array_map(fn ($s) => [...$s, 'dialogue' => [], 'continues_previous' => false], $kept));
        $shots = [];
        foreach ($kept as $i => $source) {
            $start = $source['start_ms'];
            $length = ($kept[$i + 1]['start_ms'] ?? $total) - $start;
            $parts = (int) ceil($length / (self::MAX_SECONDS * 1000));
            $lyric = mb_substr(trim((string) ($source['lyric'] ?? '')), 0, 500);
            $lipSync = $lyric !== '' && $base[$i]['character_ids'] !== [] && match ($series->lipSyncMode()) {
                'none' => false,
                'full' => (bool) ($source['lip_sync'] ?? false),
                // Chỉ điệp khúc: Claude đánh dấu sai đoạn thì bỏ.
                default => (bool) ($source['lip_sync'] ?? false) && preg_match('/chorus|refrain|hook|điệp/iu', (string) ($source['section'] ?? '')),
            };
            for ($p = 0; $p < $parts; $p++) {
                $partStart = $start + intdiv($length * $p, $parts);
                $partEnd = $start + intdiv($length * ($p + 1), $parts);
                $shots[] = [
                    ...$base[$i],
                    'duration_ms' => $partEnd - $partStart,
                    'song_ms' => $from + $partStart,
                    'lyric' => $lyric !== '' ? $lyric : null,
                    'lip_sync' => $lipSync,
                    'continues_previous' => $p > 0,
                    'camera_move' => isset(EpisodeWriter::CAMERA_MOVES[$source['camera_move'] ?? '']) ? $source['camera_move'] : 'push_in',
                    'motion' => mb_substr(trim((string) ($source['motion'] ?? '')), 0, 1500) ?: null,
                    'sound_cue' => mb_substr(trim((string) ($source['section'] ?? '')), 0, 500) ?: null,
                    'animate' => true,
                ];
            }
        }
        $list = fn ($value, int $max = 10) => array_values(array_filter(array_map(fn ($v) => mb_substr(trim((string) $v), 0, 500), array_slice((array) $value, 0, $max))));

        return [
            'shots' => $shots,
            'package' => [
                'title' => mb_substr(trim((string) ($data['title'] ?? $episode->title)), 0, 100),
                'logline' => mb_substr(trim((string) ($data['logline'] ?? '')), 0, 500),
                'continuity' => $list($data['continuity'] ?? []),
            ],
        ];
    }

    /** Mẫu khi chưa kết nối Claude (và trong test): shot đều nhau, xen kẽ hát nhép và cảnh kể chuyện. */
    private static function sample(Episode $episode): array
    {
        $series = $episode->series;
        [$from, $to] = $series->songRange();
        $names = $series->characters()->pluck('name')->all();
        $location = $series->locations()->value('name') ?? '';
        $lyrics = self::timedLyrics($series);
        $shots = [];
        for ($t = 0, $i = 0; $t < ($to - $from) / 1000; $t += 4, $i++) {
            $line = collect($lyrics)->first(fn ($l) => $l['start'] >= $t && $l['start'] < $t + 4);
            $shots[] = [
                'start' => $t, 'section' => $i === 0 ? 'intro' : ($i % 4 >= 2 ? 'chorus' : 'verse'), 'lyric' => $line['text'] ?? '',
                'lip_sync' => $line !== null && $i % 2 === 1, 'characters' => array_slice($names, 0, 1), 'location' => $location,
                'camera' => $i % 2 ? 'medium close-up' : 'wide establishing shot', 'camera_move' => $i % 2 ? 'push_in' : 'pan_right',
                'action' => $i % 2 ? 'The singer sings to the camera under warm neon light.' : 'The city at dusk, the singer walks alone.',
                'motion' => 'Hair moves in the wind, camera drifts slowly.',
            ];
        }

        return ['title' => "{$series->title} (Official Music Video)", 'logline' => 'A night walk through the city.', 'continuity' => ['Black jacket, silver necklace.'], 'shots' => $shots];
    }
}
