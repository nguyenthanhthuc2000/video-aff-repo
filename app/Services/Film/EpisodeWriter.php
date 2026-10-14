<?php

namespace App\Services\Film;

use App\Models\Episode;
use App\Models\Series;
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

    /** Chuyển động máy của shot (clip AI và ảnh tĩnh đều dùng). */
    public const CAMERA_MOVES = [
        'static' => 'Đứng yên', 'push_in' => 'Đẩy vào chậm', 'pull_out' => 'Lùi ra chậm', 'pan_left' => 'Lia sang trái',
        'pan_right' => 'Lia sang phải', 'tilt_up' => 'Ngước lên', 'tilt_down' => 'Cúi xuống',
    ];

    public const BEATS = ['hook', 'setup', 'escalation', 'twist', 'ending'];

    /** Truyện ngắn không thoại: một lượt viết trọn gói sản xuất của tập (theo kế hoạch kênh: 5 shot, prompt ảnh đầu, chuyển động, âm thanh, đăng bài). */
    public const SHORT_SYSTEM = <<<'PROMPT'
    You are the creative director and AI video production supervisor of an original animated short-form series with NO dialogue
    (vertical 9:16 for TikTok, Facebook Reels and YouTube Shorts, international audience). Write the complete production package of ONE episode.

    Story:
    - Target 20–35 seconds in total, usually five independent shots of 4–7 seconds (4–6 shots). The story must work with the sound off:
      movement, expressions, staging and objects. No spoken dialogue, no text on screen.
    - Beat map to adapt (do not force every episode into it): hook in the first 0–3 s (an image or incident that makes people curious),
      set up the problem, escalation or a choice, twist / resolution, a warm or funny final moment. Vary the rhythm and the endings.
    - Humor and warmth without repetitive tragedy, fake animal cruelty or manipulation.

    Each shot:
    - ONE principal action. Keep visual continuity between adjacent shots (same place, light, props, accessories).
    - If a moment is too complex to generate (many characters, fine paw/hand actions, objects changing state), simplify the staging
      instead of adding characters; prefer a clear close-up of the key prop.
    - action: the FIRST-FRAME image of the shot: who is where, pose, expression, key props, composition. Concrete, drawable in one picture,
      natural anatomy (animals have exactly four legs), characters named exactly, no text, signs or logos.
    - motion: the image-to-video prompt of the shot, like "Milo gently looks down at the cookie, blinks once and smiles. Soft breeze moves
      the teal scarf slightly." Only the motion inside this shot; keep key props visible.
    - camera: framing from the allowed list. camera_move: the camera movement (used for the AI clip and for stills).
    - sound: ambient sound and sound-effect cues plus the music feel (e.g. "birds chirping, tummy rumble; soft warm piano").
    - animate: true for exactly the given number of shots: the beats that need real character motion. The other shots become still images
      with the camera movement and sound effects, so choose shots that still read well as a still for them.
    - seconds: 3–8. beat: hook, setup, escalation, twist or ending.

    Package:
    - title: the English social title (max 60 characters, a curiosity hook, up to 2 emoji), like "He Gave Away His Last Cookie 🥹🍪".
    - logline: one sentence.
    - continuity: checklist of what must stay identical (accessories, prop states shot by shot, light, setting).
    - hooks: three alternative openings (what we see in the first 2 seconds, one sentence each).
    - captions: one English caption each for TikTok, Facebook Reels and YouTube Shorts (1–2 sentences, emoji ok); hashtags: 4–6.
    - qc: checklist for this episode (extra limbs, identity drift, disappearing props, incorrect anatomy, object continuity, unnatural camera
      motion), naming the risky shots and props.
    - fallback: a lower-cost version with still images, camera moves and SFX (which shots stay stills, which cutaways to use).
    - notes_vi: short production notes in Vietnamese for the creator (what is risky and how to fix it).
    - Do not invent video model features or generation prices.
    PROMPT;

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

    /** Bối cảnh cho lượt viết truyện ngắn: kinh thánh, nhân vật kèm ngoại hình, bối cảnh, vài tập trước, tập này (kèm kịch bản có sẵn). */
    private static function shortContext(Episode $episode): string
    {
        $series = $episode->series;
        $bible = $series->bible ?? [];
        $characters = self::characters($episode)->map(fn (SeriesCharacter $c) => "- {$c->name}: {$c->appearance} Personality: {$c->personality}")->implode("\n");
        $locations = $series->locations()->get()->map(fn (SeriesLocation $l) => "- {$l->name}: {$l->description}")->implode("\n");
        $previous = $series->episodes()->where('number', '<', $episode->number)->orderByDesc('number')->limit(5)->get()->reverse()
            ->map(fn (Episode $e) => "- Episode {$e->number} \"{$e->title}\": ".($e->package['logline'] ?? $e->synopsis))->implode("\n");

        return "Series: {$series->title}\n"
            .(isset($bible['logline']) ? "Logline: {$bible['logline']}\n" : '')
            .(isset($bible['world']) ? "World: {$bible['world']}\n" : '')
            .(isset($bible['tone']) ? "Tone: {$bible['tone']}\n" : '')
            .'Audience: '.($series->audience ?: Series::SHORT_AUDIENCE).".\n"
            ."Art style: {$series->style}.\n\n"
            ."Characters:\n{$characters}\n\n"
            .($locations !== '' ? "Locations:\n{$locations}\n\n" : '')
            .($previous !== '' ? "Previous episodes (do not repeat their motifs):\n{$previous}\n\n" : '')
            ."This episode: Episode {$episode->number} \"{$episode->title}\"\nSynopsis: {$episode->synopsis}\n"
            .($episode->kind ? 'Kind: '.$episode->kind."\n" : '')
            .(filled($episode->brief) ? "\nThe creator already wrote this script (may be in Vietnamese). Follow its shots, timing, actions, prompts and sounds "
                ."closely, write everything in English and only improve what is needed:\n{$episode->brief}\n" : '');
    }

    public static function shortSchema(): array
    {
        $string = ['type' => 'string'];
        $list = ['type' => 'array', 'items' => $string];

        return [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['title', 'logline', 'shots', 'continuity', 'hooks', 'captions', 'hashtags', 'qc', 'fallback', 'notes_vi'],
            'properties' => [
                'title' => $string, 'logline' => $string,
                'shots' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['beat', 'location', 'characters', 'camera', 'camera_move', 'action', 'motion', 'sound', 'seconds', 'animate'],
                    'properties' => [
                        'beat' => ['type' => 'string', 'enum' => self::BEATS],
                        'location' => $string,
                        'characters' => $list,
                        'camera' => ['type' => 'string', 'enum' => self::CAMERAS],
                        'camera_move' => ['type' => 'string', 'enum' => array_keys(self::CAMERA_MOVES)],
                        'action' => $string, 'motion' => $string, 'sound' => $string,
                        'seconds' => ['type' => 'number'],
                        'animate' => ['type' => 'boolean'],
                    ],
                ]],
                'continuity' => $list, 'hooks' => $list,
                'captions' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['tiktok', 'reels', 'shorts'],
                    'properties' => ['tiktok' => $string, 'reels' => $string, 'shorts' => $string]],
                'hashtags' => $list, 'qc' => $list, 'fallback' => $string, 'notes_vi' => $string,
            ],
        ];
    }

    /**
     * Truyện ngắn không thoại: shot (đã chuẩn hoá, kèm chuyển động, âm thanh, có làm clip AI không) + gói sản xuất / đăng bài.
     *
     * @return array{shots: list<array>, package: array}
     *
     * @throws ScriptWriterException
     */
    public static function short(Episode $episode): array
    {
        $budget = $episode->series->clipBudget();
        $text = self::shortContext($episode)
            ."\nAllowed camera values: ".implode(', ', self::CAMERAS).".\nMark exactly {$budget} shot(s) with animate = true (all shots if there are fewer)."
            ."\nWrite the production package.";
        $data = FilmClaude::json(self::SHORT_SYSTEM, $text, self::shortSchema(), 'film_episode', "film_episode:{$episode->id}", 10000, fn () => self::sampleShort($episode));

        return self::normalizeShort($episode, (array) $data, $budget);
    }

    /** @return array{shots: list<array>, package: array} */
    public static function normalizeShort(Episode $episode, array $data, int $budget): array
    {
        $raw = array_values(array_slice((array) ($data['shots'] ?? []), 0, 8));
        $shots = self::normalizeShots($episode, array_map(fn ($s) => [...$s, 'dialogue' => [], 'continues_previous' => false], $raw));
        $animated = 0;
        foreach ($shots as $i => &$shot) {
            $source = $raw[$i] ?? [];
            $shot['duration_ms'] = (int) round(max(3, min(8, (float) ($source['seconds'] ?? 5))) * 1000);
            $shot['camera_move'] = isset(self::CAMERA_MOVES[$source['camera_move'] ?? '']) ? $source['camera_move'] : 'push_in';
            $shot['motion'] = mb_substr(trim((string) ($source['motion'] ?? '')), 0, 1500) ?: null;
            $shot['sound_cue'] = mb_substr(trim((string) ($source['sound'] ?? '')), 0, 500) ?: null;
            // Đúng số clip AI đã chọn: ưu tiên shot Claude đánh dấu, thiếu thì để ảnh tĩnh.
            $shot['animate'] = (bool) ($source['animate'] ?? false) && $animated < $budget;
            $animated += $shot['animate'] ? 1 : 0;
        }
        unset($shot);
        $list = fn ($value, int $max = 10) => array_values(array_filter(array_map(fn ($v) => mb_substr(trim((string) $v), 0, 500), array_slice((array) $value, 0, $max))));
        $captions = (array) ($data['captions'] ?? []);

        return [
            'shots' => $shots,
            'package' => [
                'title' => mb_substr(trim((string) ($data['title'] ?? $episode->title)), 0, 100),
                'logline' => mb_substr(trim((string) ($data['logline'] ?? '')), 0, 500),
                'beats' => array_map(fn ($s) => in_array($s['beat'] ?? null, self::BEATS, true) ? $s['beat'] : null, array_slice($raw, 0, count($shots))),
                'continuity' => $list($data['continuity'] ?? []),
                'hooks' => $list($data['hooks'] ?? [], 3),
                'captions' => collect(['tiktok', 'reels', 'shorts'])->mapWithKeys(fn ($k) => [$k => mb_substr(trim((string) ($captions[$k] ?? '')), 0, 500)])->all(),
                'hashtags' => array_map(fn ($t) => '#'.ltrim(preg_replace('/\s+/u', '', $t), '#'), $list($data['hashtags'] ?? [], 8)),
                'qc' => $list($data['qc'] ?? [], 12),
                'fallback' => mb_substr(trim((string) ($data['fallback'] ?? '')), 0, 2000),
                'notes_vi' => mb_substr(trim((string) ($data['notes_vi'] ?? '')), 0, 2000),
            ],
        ];
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

    private static function sampleShort(Episode $episode): array
    {
        $names = self::characters($episode)->take(2)->pluck('name')->all();
        $second = $names[1] ?? $names[0];
        $location = $episode->series->locations()->value('name') ?? 'Village park';
        $shot = fn (string $beat, array $who, string $camera, string $move, string $action, string $motion, string $sound, float $seconds, bool $animate) => [
            'beat' => $beat, 'location' => $location, 'characters' => $who, 'camera' => $camera, 'camera_move' => $move, 'action' => $action,
            'motion' => $motion, 'sound' => $sound, 'seconds' => $seconds, 'animate' => $animate,
        ];

        return [
            'title' => 'He Gave Away His Last Cookie 🥹🍪',
            'logline' => "{$names[0]} shares the last cookie with {$second} and gets a sweet surprise back.",
            'shots' => [
                $shot('hook', [$names[0]], 'medium shot', 'push_in', "{$names[0]} sits on a wooden bench holding one golden cookie, delighted.", "{$names[0]} looks down at the cookie, blinks and smiles.", 'birds chirping; soft playful music', 5, true),
                $shot('setup', [$names[0], $second], 'two-shot', 'static', "{$second} stops beside the bench and stares at the cookie, tummy rumbling.", "{$second} slowly approaches, {$names[0]} turns his head.", 'tummy rumble, small whimper', 6, true),
                $shot('escalation', [$names[0], $second], 'close-up', 'push_in', "{$names[0]} breaks the cookie and offers the bigger half to {$second}.", "{$names[0]} splits the cookie and holds out the larger piece.", 'crunch; warm piano', 7, false),
                $shot('twist', [$names[0], $second], 'medium close-up', 'push_in', "{$second} pulls a small cup of milk from a tiny backpack.", "{$second} sets the cup between them, {$names[0]} widens his eyes.", 'pop, chime', 7, true),
                $shot('ending', [$names[0], $second], 'two-shot', 'pull_out', "{$names[0]} and {$second} share cookie and milk at sunset; {$second} has a milk moustache.", "{$names[0]} laughs silently.", 'cute ending music', 5, false),
            ],
            'continuity' => ['Teal scarf on every shot', 'Cookie whole in shot 1, two halves from shot 3'],
            'hooks' => ['A tiny kitten guards one last cookie.', 'A hungry corgi tummy rumbles loudly.', 'One cookie, two friends.'],
            'captions' => ['tiktok' => 'Sometimes the smallest kindness makes the sweetest memory. 🐱🐶', 'reels' => 'Share the last cookie. 🍪', 'shorts' => 'He gave away his last cookie 🥹'],
            'hashtags' => ['CatStories', '#AnimatedShorts', 'Wholesome'],
            'qc' => ['Shot 3: cookie halves must not melt or merge', 'Count legs on every shot'],
            'fallback' => 'Keep shots 3 and 5 as stills with push-in and SFX.',
            'notes_vi' => 'Cảnh bẻ bánh dễ lỗi vật thể, nên để ảnh tĩnh.',
        ];
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
