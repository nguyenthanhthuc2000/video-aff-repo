<?php

namespace App\Services\Film;

use App\Models\Episode;
use App\Models\SeriesCharacter;
use App\Models\SeriesLocation;
use App\Services\Script\ScriptWriterException;
use Illuminate\Support\Collection;

/**
 * Claude viết một tập: lượt 1 dàn ý (các phân đoạn), sau đó từng phân đoạn thành shot (góc máy, hành động, lời thoại, thời lượng).
 * Chia nhỏ để mỗi lượt trả lời ngắn, ổn định; mỗi phân đoạn nhận vài shot cuối của phân đoạn trước để nối mạch.
 */
final class EpisodeWriter
{
    /** Giây trung bình mỗi phân đoạn khi chia dàn ý. */
    public const SEQUENCE_SECONDS = 40;

    public const CAMERAS = [
        'wide establishing shot', 'full shot', 'medium shot', 'medium close-up', 'close-up', 'extreme close-up',
        'two-shot', 'over-the-shoulder shot', 'low angle shot', 'high angle shot', 'point-of-view shot', 'insert shot of an object',
    ];

    public const NARRATOR = 'Narrator';

    public const OUTLINE_SYSTEM = <<<'PROMPT'
    You are the head writer of an original English animated series for a YouTube kids channel.
    Break ONE episode into sequences (scenes of the story) that together tell the episode synopsis from start to end.

    Rules:
    - The first sequence is a hook: something intriguing or funny within the first 15 seconds.
    - Clear structure: setup, problem, attempts (with a funny or surprising turn), solution, ending that shows the lesson through actions.
    - Use only the listed characters and preferably the listed locations (a new location is allowed only if the story needs it; give it a short visual name).
    - "seconds" of all sequences add up to the target episode length.
    - summary: 2–4 sentences of what happens, who does what, and how the sequence ends.
    PROMPT;

    public const SEQUENCE_SYSTEM = <<<'PROMPT'
    You are the storyboard writer of an original English 2D animated series for a YouTube kids channel.
    Write the shots of ONE sequence of the episode. Each shot becomes one drawn keyframe, then a short animated clip, with voice acting.

    Rules for shots:
    - 2–8 seconds each; a shot with dialogue lasts long enough to speak the line (about 2.5 words per second plus a short pause).
    - camera: pick from the allowed list; vary it like a TV cartoon (establishing wide shot when entering a location, close-ups for emotions,
      two-shots for conversations, inserts for clues/objects).
    - action: what we SEE in this frame, concrete and drawable in one picture, plus one simple movement (e.g. "Leo leans over the map and points at a red X,
      eyebrows raised"). Name characters exactly. No text, signs or writing that must be readable.
    - characters: the characters visible in the frame (prefer 1–2, at most 3).
    - dialogue: lines spoken during the shot, speaker is a character name or "Narrator" (use the narrator sparingly). Short, natural lines for the audience age.
      emotion: one word (happy, curious, surprised, worried, excited, sad, scared, proud, thinking, laughing, angry, calm).
    - continues_previous: true when this shot continues the previous shot without a cut (same place, same camera, the action goes on).
    - Keep continuity with the previous shots given; end the sequence where its summary ends.
    PROMPT;

    /** Nhân vật dùng trong tập: nhân vật đã chọn cho tập, không chọn thì cả bộ phim. */
    public static function characters(Episode $episode): Collection
    {
        $all = $episode->series->characters()->get();
        $chosen = $all->whereIn('id', $episode->character_ids ?? []);

        return $chosen->isNotEmpty() ? $chosen->values() : $all;
    }

    private static function context(Episode $episode): string
    {
        $series = $episode->series;
        $bible = $series->bible ?? [];
        $characters = self::characters($episode)->map(fn (SeriesCharacter $c) => "- {$c->name} ({$c->role}".($c->age !== null ? ", {$c->age}" : '')."): {$c->personality}")->implode("\n");
        $locations = $series->locations()->get()->map(fn (SeriesLocation $l) => "- {$l->name}: {$l->description}")->implode("\n");
        $previous = $series->episodes()->where('number', '<', $episode->number)->orderByDesc('number')->limit(3)->get()->reverse()
            ->map(fn (Episode $e) => "- Episode {$e->number} \"{$e->title}\": ".($e->summary ?: $e->synopsis))->implode("\n");

        return "Series: {$series->title}\n"
            .(isset($bible['logline']) ? "Logline: {$bible['logline']}\n" : '')
            .(isset($bible['world']) ? "World: {$bible['world']}\n" : '')
            .(isset($bible['tone']) ? "Tone: {$bible['tone']}\n" : '')
            .AgeRules::prompt($series->age_min, $series->age_max)."\n\n"
            ."Characters:\n{$characters}\n\n"
            .($locations !== '' ? "Locations:\n{$locations}\n\n" : '')
            .($previous !== '' ? "Previous episodes:\n{$previous}\n\n" : '')
            ."This episode: Episode {$episode->number} \"{$episode->title}\"\nSynopsis: {$episode->synopsis}\n"
            .($episode->lesson ? "Lesson: {$episode->lesson}\n" : '');
    }

    public static function outlineSchema(): array
    {
        $string = ['type' => 'string'];

        return [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['sequences'],
            'properties' => ['sequences' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['title', 'summary', 'location', 'characters', 'seconds'],
                'properties' => [
                    'title' => $string, 'summary' => $string, 'location' => $string,
                    'characters' => ['type' => 'array', 'items' => $string],
                    'seconds' => ['type' => 'integer'],
                ],
            ]]],
        ];
    }

    public static function sequenceSchema(): array
    {
        $string = ['type' => 'string'];

        return [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['shots'],
            'properties' => ['shots' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['location', 'characters', 'camera', 'action', 'dialogue', 'seconds', 'continues_previous'],
                'properties' => [
                    'location' => $string,
                    'characters' => ['type' => 'array', 'items' => $string],
                    'camera' => ['type' => 'string', 'enum' => self::CAMERAS],
                    'action' => $string,
                    'dialogue' => ['type' => 'array', 'items' => [
                        'type' => 'object', 'additionalProperties' => false, 'required' => ['speaker', 'line', 'emotion'],
                        'properties' => ['speaker' => $string, 'line' => $string, 'emotion' => $string],
                    ]],
                    'seconds' => ['type' => 'number'],
                    'continues_previous' => ['type' => 'boolean'],
                ],
            ]]],
        ];
    }

    /**
     * @return list<array{title: string, summary: string, location: string, characters: list<string>, seconds: int}>
     *
     * @throws ScriptWriterException
     */
    public static function outline(Episode $episode): array
    {
        $seconds = $episode->series->episode_seconds;
        $count = max(2, (int) round($seconds / self::SEQUENCE_SECONDS));
        $text = self::context($episode)."\nTarget length: about {$seconds} seconds, in {$count} sequences (one may be shorter or longer).\nWrite the sequences.";

        $data = FilmClaude::json(self::OUTLINE_SYSTEM, $text, self::outlineSchema(), 'film_episode', "film_episode:{$episode->id}", 4000, fn () => self::sampleOutline($episode));
        $sequences = collect($data['sequences'] ?? [])->take(12)->map(fn ($s) => [
            'title' => mb_substr(trim((string) ($s['title'] ?? '')), 0, 120),
            'summary' => mb_substr(trim((string) ($s['summary'] ?? '')), 0, 1500),
            'location' => mb_substr(trim((string) ($s['location'] ?? '')), 0, 80),
            'characters' => array_values(array_map(fn ($n) => mb_substr(trim((string) $n), 0, 80), (array) ($s['characters'] ?? []))),
            'seconds' => max(10, min(120, (int) ($s['seconds'] ?? self::SEQUENCE_SECONDS))),
        ])->filter(fn ($s) => $s['summary'] !== '')->values()->all();

        if ($sequences === []) {
            throw new ScriptWriterException('Claude không trả về dàn ý tập.');
        }

        return $sequences;
    }

    /**
     * Shot của phân đoạn $index (đã chuẩn hoá: id nhân vật, id bối cảnh, ms).
     *
     * @param  list<array>  $sequences
     * @param  list<string>  $previousShots  Mô tả vài shot cuối đã viết (nối mạch).
     * @return list<array{series_location_id: ?int, character_ids: list<int>, camera: string, action: string, dialogue: list<array>, duration_ms: int, continues_previous: bool}>
     *
     * @throws ScriptWriterException
     */
    public static function sequence(Episode $episode, array $sequences, int $index, array $previousShots): array
    {
        $outline = collect($sequences)->map(fn ($s, $i) => ($i === $index ? '>> ' : '   ').'Sequence '.($i + 1)." \"{$s['title']}\" ({$s['seconds']}s, {$s['location']}): {$s['summary']}")->implode("\n");
        $current = $sequences[$index];
        $text = self::context($episode)
            ."\nEpisode outline (>> marks the sequence to write now):\n{$outline}\n\n"
            .($previousShots !== [] ? "Last shots before this sequence:\n".implode("\n", $previousShots)."\n\n" : '')
            .'Write the shots of sequence '.($index + 1)." \"{$current['title']}\": about {$current['seconds']} seconds in total, "
            .'about '.max(3, (int) round($current['seconds'] / 5)).' shots. Allowed camera values: '.implode(', ', self::CAMERAS).'.';

        $data = FilmClaude::json(self::SEQUENCE_SYSTEM, $text, self::sequenceSchema(), 'film_episode', "film_episode:{$episode->id}", 8000, fn () => self::sampleSequence($episode, $current));

        return self::normalizeShots($episode, (array) ($data['shots'] ?? []));
    }

    /** Tên → id (không phân biệt hoa thường); người nói lạ thành dẫn chuyện, nhân vật lạ bị bỏ; thời lượng đủ đọc lời thoại. */
    public static function normalizeShots(Episode $episode, array $shots): array
    {
        $characters = $episode->series->characters()->get()->keyBy(fn ($c) => mb_strtolower($c->name));
        $firstNames = $episode->series->characters()->get()->keyBy(fn ($c) => mb_strtolower(strtok($c->name, ' ')));
        $find = fn (string $name) => $characters[mb_strtolower(trim($name))] ?? $firstNames[mb_strtolower(trim($name))] ?? null;
        $locations = $episode->series->locations()->get()->keyBy(fn ($l) => mb_strtolower($l->name));

        $result = collect($shots)->take(40)->map(function ($shot) use ($find, $locations) {
            $dialogue = collect((array) ($shot['dialogue'] ?? []))->map(fn ($d) => [
                'character_id' => ($c = $find((string) ($d['speaker'] ?? ''))) ? $c->id : null,
                'line' => mb_substr(trim((string) ($d['line'] ?? '')), 0, 500),
                'emotion' => mb_substr(mb_strtolower(trim((string) ($d['emotion'] ?? ''))), 0, 30) ?: null,
            ])->filter(fn ($d) => $d['line'] !== '')->values()->all();
            $words = array_sum(array_map(fn ($d) => str_word_count($d['line']), $dialogue));
            $seconds = max((float) ($shot['seconds'] ?? 4), $words > 0 ? $words / 2.5 + 0.8 : 2);
            $locationName = trim((string) ($shot['location'] ?? ''));
            $location = $locations[mb_strtolower($locationName)] ?? null;
            $action = mb_substr(trim((string) ($shot['action'] ?? '')), 0, 1500);

            return [
                'series_location_id' => $location?->id,
                'character_ids' => collect((array) ($shot['characters'] ?? []))->map(fn ($n) => $find((string) $n)?->id)->filter()->unique()->take(4)->values()->all(),
                'camera' => in_array($shot['camera'] ?? null, self::CAMERAS, true) ? $shot['camera'] : 'medium shot',
                // Bối cảnh mới (không có trong danh sách): giữ tên trong mô tả để vẽ đúng nơi.
                'action' => $location === null && $locationName !== '' ? "At {$locationName}: {$action}" : $action,
                'dialogue' => $dialogue,
                'duration_ms' => (int) round(max(2, min(10, $seconds)) * 1000),
                'continues_previous' => (bool) ($shot['continues_previous'] ?? false),
            ];
        })->filter(fn ($s) => $s['action'] !== '')->values()->all();

        if ($result === []) {
            throw new ScriptWriterException('Claude không trả về shot nào cho phân đoạn.');
        }

        return $result;
    }

    /** Một dòng tóm tắt shot (đưa vào lượt viết phân đoạn sau). */
    public static function describe(array $shot, Collection $characters): string
    {
        $lines = collect($shot['dialogue'] ?? [])->map(fn ($d) => ($characters->firstWhere('id', $d['character_id'])?->name ?? self::NARRATOR).": \"{$d['line']}\"")->implode(' ');

        return "- [{$shot['camera']}] {$shot['action']}".($lines !== '' ? " {$lines}" : '');
    }

    private static function sampleOutline(Episode $episode): array
    {
        $names = self::characters($episode)->take(2)->pluck('name')->all();
        $location = $episode->series->locations()->value('name') ?? 'Town park';

        return ['sequences' => [
            ['title' => 'A strange clue', 'summary' => "{$names[0]} finds a strange clue and calls a friend.", 'location' => $location, 'characters' => $names, 'seconds' => 20],
            ['title' => 'Solving it', 'summary' => 'They follow the clue and solve the mystery together.', 'location' => $location, 'characters' => $names, 'seconds' => 20],
        ]];
    }

    private static function sampleSequence(Episode $episode, array $sequence): array
    {
        $names = self::characters($episode)->take(2)->pluck('name')->all();
        $second = $names[1] ?? $names[0];

        return ['shots' => [
            ['location' => $sequence['location'], 'characters' => [], 'camera' => 'wide establishing shot', 'action' => 'A sunny morning over the town.', 'dialogue' => [['speaker' => self::NARRATOR, 'line' => 'It was a quiet morning in town.', 'emotion' => 'calm']], 'seconds' => 4, 'continues_previous' => false],
            ['location' => $sequence['location'], 'characters' => [$names[0]], 'camera' => 'close-up', 'action' => "{$names[0]} spots something shiny and gasps.", 'dialogue' => [['speaker' => $names[0], 'line' => 'Whoa, what is that?', 'emotion' => 'surprised']], 'seconds' => 3, 'continues_previous' => false],
            ['location' => $sequence['location'], 'characters' => [$names[0], $second, 'Nobody'], 'camera' => 'two-shot', 'action' => "{$names[0]} and {$second} look at the clue together.", 'dialogue' => [['speaker' => $second, 'line' => 'Let us find out together, step by step, before lunch time!', 'emotion' => 'excited']], 'seconds' => 2, 'continues_previous' => false],
        ]];
    }
}
