<?php

namespace App\Services\TopicShort;

use App\Models\MangaPanel;
use App\Models\TopicShort;
use App\Services\Film\FilmClaude;
use App\Services\Manga\MangaCatalog;
use App\Services\Script\ScriptWriterException;
use App\Support\DragonBall;

/** Claude viết lời kể cho short theo chủ đề (anime / lore), chia cảnh kèm từ khoá tìm ảnh wiki và prompt vẽ ảnh. */
class ShortWriter
{
    public const HOOK_STYLES = ['question', 'shock', 'number'];

    /** Cảm xúc cảnh: chọn nhạc nền, hiệu ứng âm thanh. */
    public const MOODS = ['tense', 'sad', 'epic', 'funny', 'calm'];

    /** Số khung tối đa của một trang manga. */
    public const MAX_PANELS = 3;

    /** Số từ đọc mỗi giây (ước lượng để Claude viết đúng độ dài). */
    public const WORDS_PER_SECOND = ['vi' => 3.0, 'en' => 2.5];

    public const SYSTEM = <<<'PROMPT'
    You write narration for vertical YouTube Shorts / TikTok videos (9:16, 30–60 seconds) for an anime recap & analysis channel (Dragon Ball, Jujutsu Kaisen...): fast, punchy, fan-to-fan commentary, never a full episode retelling.

    Structure (follow it, the requested format below says how the body is organised):
    1. Hook, first 3 seconds (scene 1): one shocking question or bold claim, under 12 words, that makes viewers stay. Scene 1 shows the most famous character of the topic, face clearly visible.
    2. Context (scene 2, 1–2 sentences): set up the situation, usually showing the second character or the villain.
    3. Body: 3–4 fast points, one scene each, 4–7 seconds of speech per scene.
    4. Twist / climax: save the most surprising detail or the most powerful moment (e.g. the transformation) for the second-to-last scene.
    5. Ending (last scene): ask the viewers a direct question that invites them to comment (e.g. "Theo các bạn thì...? Comment cho mình biết nhé.").

    Rules:
    - Facts must match the original work (canon). Never invent events, quotes or numbers. If unsure about a detail, leave it out. Theories must be clearly framed as theories.
    - Tone: fast, casual, friendly; in Vietnamese address the audience as "các bạn" and the narrator as "mình". Short sentences, no long intro, no filler.
    - Narration is spoken by a TTS voice: short, clear sentences, no emojis, no hashtags, no stage directions, numbers written as words when spoken naturally.
    - 6–8 scenes in total.
    - Write narration, on_screen_text, title and caption in the requested language. Write image_query and image_prompt in English.
    - Split into scenes of 4–7 seconds of speech each. Each scene shows one picture.
    - on_screen_text: 2–5 words, punchy keywords in capitals, shown big on screen (e.g. "SỨC MẠNH THẬT SỰ", "KHÔNG AI NGỜ").
    - title: phrased as a question or a "fact" that sparks curiosity.
    - image_query: 2–3 English keywords, most important first, to find a matching screenshot or artwork on a fan wiki by file name (character name + form + event/place, e.g. "Goku Super Saiyan"). Use official character names here.
    - characters: the main recurring characters of the short (at most 5), each with the official English name and look: a precise description of their canonical appearance at this point of the story (face, hair style and color, eyes, body, outfit and colors, aura), so an image model can draw them on-model.
    - art_style: one line naming the original work and its creator's character design, without the medium (e.g. "Dragon Ball, Akira Toriyama character design"); the medium (manga or anime) is added separately.
    - scene expression: facial expression of the main character in that picture, one code: E1 calm, E2 cheerful smile, E3 stern, E4 surprised, E5 determined, E6 thoughtful, E7 confident smirk, E8 focused ready stance, E9 puzzled.
    - scene characters: names (exactly as in characters) of who appears in that scene's picture; empty if nobody.
    - image_prompt: English prompt for an AI image generator to draw that scene's picture, vertical 9:16. Name the characters by their official names and describe pose, expression, action, camera angle, setting, lighting and mood as in the original scene.
    - caption: 1–2 sentences for the post. hashtags: #shorts, #anime, the franchise tags (e.g. #dragonball #7vienngocrong for Dragon Ball) and 1–2 character tags.
    - Write original narration; never copy titles or scripts from other channels.
    - hooks: exactly 3 alternative hooks for scene 1, each under 14 words, one per style: "question" (a curiosity question), "shock" (a bold or shocking claim), "number" (a number, record or comparison). Scene 1 narration must be exactly hooks[0].text.
    - scene mood: one of tense, sad, epic, funny, calm (drives background music and sound effects).
    - scene panels: the scene's picture as a vertical manga page of 1–3 panels stacked top to bottom (use 2–3 panels for action or a reaction, 1 big panel for a key moment). Each panel: character = a name from our picture library list exactly as given, pose = one code that exists for that character (faces E1–E9 for everyone; fighting A1 punch, A2 kick, A3 guard, A4 dash, A5 charging ki, A6 knocked back; S1–S4 signature moves as listed), camera = a few English words (e.g. "close-up", "low angle full body"). Pictures come ONLY from this library (no web images), so give every scene 1–3 panels with library characters; leave panels empty only when no library character can fit at all.
    - scene manga_panels: when a list of real manga panels is given, ids of 1–3 of them (top to bottom, in story order) that show exactly what this scene's narration says. Viewers want real manga art, so prefer real panels over library panels whenever one fits the scene; do not reuse a panel in two scenes; empty when none fits or no list is given (then the library panels are used).
    - scene fact_ids: ids of the research facts this scene is based on (empty when there is no research brief).
    PROMPT;

    /** Luật thêm khi có bản tư liệu đã duyệt: chỉ viết từ tư liệu, gắn nguồn. */
    public const RESEARCH_RULES = <<<'PROMPT'
    A research brief approved by the channel owner is given. It is the ONLY source of truth:
    - Every factual statement in the narration must come from a fact in the brief; put its id in fact_ids. Never add events, quotes, numbers or names that are not in the brief.
    - Facts of kind "theory" may only be told as opinions ("nhiều fan cho rằng...", "có giả thuyết...").
    - Quotes: keep them short, in Vietnamese.
    - Follow the owner's notes when given.
    PROMPT;

    /** Cách tổ chức phần thân theo dạng short. */
    public const FORMATS = [
        'moment' => 'Moment recap: retell that one moment in order with rising tension; context, the build-up beats, then the climax; end with a question about how fans felt or what they think.',
        'facts' => 'Little-known facts: 3–4 surprising, verifiable facts about the topic, one per scene, the most surprising one last; end by asking which fact viewers did not know.',
        'versus' => 'Hypothetical versus / theory: compare the two sides on 3–4 points (power, techniques, feats, weaknesses) using canon feats only, give a reasoned verdict as the twist, clearly framed as opinion; end by asking who viewers think wins.',
        'top5' => 'Top 5 ranking: count down from number 5 to number 1, one scene per entry with the reason in one sentence, number 1 is the twist; end by asking viewers for their own number 1.',
    ];

    /**
     * @return array{title: string, scenes: list<array{narration: string, on_screen_text: string, image_query: string, image_prompt: string}>, caption: string, hashtags: list<string>}
     *
     * @throws ScriptWriterException
     */
    public static function write(TopicShort $short): array
    {
        $language = $short->language === 'en' ? 'English' : 'Vietnamese (natural, as spoken by Vietnamese anime fans; keep well-known names like Goku, Super Saiyan as is)';
        $words = (int) round($short->target_seconds * self::WORDS_PER_SECOND[$short->language === 'en' ? 'en' : 'vi']);
        $format = self::FORMATS[$short->style] ?? self::FORMATS['moment'];
        $known = implode(', ', array_column(DragonBall::CHARACTERS, 0));
        $text = "Topic: {$short->topic}\nFormat: {$format}\nLanguage: {$language}\nTarget length: about {$short->target_seconds} seconds of narration, about {$words} words in total.";

        $text .= "\nDragon Ball characters in our picture library (when one of them appears, use exactly this name, including the form, e.g. \"Goku Super Saiyan\"): {$known}.";
        $text .= "\nSignature move codes per library character: ".collect(DragonBall::SKILLS)
            ->map(fn ($moves, $key) => DragonBall::CHARACTERS[$key][0].' '.collect($moves)->map(fn ($m, $code) => "{$code} {$m[0]}")->implode(', '))->implode('; ').'.';
        $system = self::SYSTEM;
        if ($short->use_research && ($facts = $short->approvedFacts()) !== []) {
            $system .= "\n\n".self::RESEARCH_RULES;
            $text .= "\n\nResearch brief (approved):\nSummary: ".($short->research['summary'] ?? '')."\nFacts (id [kind] era: text):\n"
                .collect($facts)->map(fn ($f) => "{$f['id']} [{$f['kind']}] {$f['era']}: {$f['text']}")->implode("\n");
            if (filled($short->research_notes)) {
                $text .= "\nOwner's notes: {$short->research_notes}";
            }
        }
        if ($short->topic_series_id && ($series = $short->series)) {
            $outline = collect($series->outline ?? [])->map(fn ($p, $i) => 'Part '.($i + 1).": {$p['title']} — {$p['focus']}")->implode("\n");
            $current = $series->outline[$short->part - 1] ?? null;
            $text .= "\n\nThis video is part {$short->part} of {$series->parts} of a series about \"{$series->topic}\".\nSeries outline:\n{$outline}\n"
                .'Cover ONLY this part'.($current ? ": {$current['title']} — {$current['focus']}" : '').'. '
                .(($current['fact_ids'] ?? []) !== [] ? 'Build it mainly from facts '.implode(', ', $current['fact_ids']).'. ' : '')
                .($short->part > 1 ? 'Start scene 2 with a one-sentence recap of the previous part. ' : '')
                .($short->part < $series->parts ? 'End with a teaser for the next part and "Xem phần tiếp nhé" instead of a question.' : 'End the series with a question to the viewers.');
        }

        $catalog = MangaCatalog::for($short);
        if ($catalog->isNotEmpty()) {
            $text .= "\n\nReal manga panels available (id [chapter] characters | mood: what happens):\n".$catalog->map(fn (MangaPanel $panel) => MangaCatalog::line($panel))->implode("\n");
        }

        $data = FilmClaude::json($system, $text, self::schema(), 'topic_short', "topic_short:{$short->id}", 8000, fn () => self::sample($short, $catalog->pluck('id')->all()));

        return self::normalize($data, array_column($short->approvedFacts(), 'id'), $catalog->pluck('id')->all());
    }

    public static function schema(): array
    {
        $string = ['type' => 'string'];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['title', 'characters', 'art_style', 'hooks', 'scenes', 'caption', 'hashtags'],
            'properties' => [
                'title' => $string,
                'hooks' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['style', 'text'],
                        'properties' => ['style' => ['type' => 'string', 'enum' => self::HOOK_STYLES], 'text' => $string],
                    ],
                ],
                'characters' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['name', 'look'],
                        'properties' => ['name' => $string, 'look' => $string],
                    ],
                ],
                'art_style' => $string,
                'scenes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['narration', 'on_screen_text', 'image_query', 'image_prompt', 'characters', 'expression', 'mood', 'panels', 'manga_panels', 'fact_ids'],
                        'properties' => [
                            'narration' => $string,
                            'on_screen_text' => $string,
                            'image_query' => $string,
                            'image_prompt' => $string,
                            'characters' => ['type' => 'array', 'items' => $string],
                            'expression' => ['type' => 'string', 'enum' => array_keys(DragonBall::EXPRESSIONS)],
                            'mood' => ['type' => 'string', 'enum' => self::MOODS],
                            'panels' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['character', 'pose', 'camera'],
                                    'properties' => ['character' => $string, 'pose' => $string, 'camera' => $string],
                                ],
                            ],
                            'manga_panels' => ['type' => 'array', 'items' => ['type' => 'integer']],
                            'fact_ids' => ['type' => 'array', 'items' => $string],
                        ],
                    ],
                ],
                'caption' => $string,
                'hashtags' => ['type' => 'array', 'items' => $string],
            ],
        ];
    }

    /**
     * @param  list<string>  $factIds  Id tư liệu đã duyệt (fact_ids ngoài danh sách bị bỏ).
     * @param  list<int>  $panelIds  Id khung manga thật đã gửi cho Claude (id khác bị bỏ, mỗi khung chỉ dùng một lần).
     *
     * @throws ScriptWriterException
     */
    public static function normalize(mixed $data, array $factIds = [], array $panelIds = []): array
    {
        $used = [];
        $characters = [];
        foreach ((array) ($data['characters'] ?? []) as $character) {
            $name = mb_substr(trim((string) ($character['name'] ?? '')), 0, 80);
            if ($name !== '' && ! collect($characters)->contains(fn ($c) => mb_strtolower($c['name']) === mb_strtolower($name))) {
                $characters[] = ['name' => $name, 'look' => mb_substr(trim((string) ($character['look'] ?? '')), 0, 600), 'image_path' => null];
            }
        }
        $characters = array_slice($characters, 0, 6);
        $names = array_column($characters, 'name');

        $scenes = [];
        foreach ((array) ($data['scenes'] ?? []) as $scene) {
            $narration = trim((string) ($scene['narration'] ?? ''));
            if ($narration === '') {
                continue;
            }
            $scenes[] = [
                'narration' => mb_substr($narration, 0, 1000),
                'on_screen_text' => mb_substr(trim((string) ($scene['on_screen_text'] ?? '')), 0, 120),
                'image_query' => mb_substr(trim((string) ($scene['image_query'] ?? '')), 0, 200),
                'image_prompt' => mb_substr(trim((string) ($scene['image_prompt'] ?? '')), 0, 2000),
                'expression' => isset(DragonBall::EXPRESSIONS[$scene['expression'] ?? '']) ? $scene['expression'] : null,
                // Chỉ giữ tên có trong danh sách nhân vật (so không phân biệt hoa thường).
                'characters' => array_values(array_unique(array_filter(array_map(
                    fn ($name) => collect($names)->first(fn ($n) => mb_strtolower($n) === mb_strtolower(trim((string) $name))),
                    (array) ($scene['characters'] ?? []),
                )))),
                'mood' => in_array($scene['mood'] ?? null, self::MOODS, true) ? $scene['mood'] : null,
                'panels' => self::panels((array) ($scene['panels'] ?? [])),
                'manga_panels' => self::mangaPanels((array) ($scene['manga_panels'] ?? []), $panelIds, $used),
                'fact_ids' => array_values(array_unique(array_intersect(array_map('strval', (array) ($scene['fact_ids'] ?? [])), $factIds))),
            ];
        }
        if (count($scenes) < 2) {
            throw new ScriptWriterException('Claude trả về quá ít cảnh, thử viết lại.');
        }

        $hooks = [];
        foreach ((array) ($data['hooks'] ?? []) as $hook) {
            $text = mb_substr(trim((string) ($hook['text'] ?? '')), 0, 300);
            if ($text !== '' && count($hooks) < 3) {
                $hooks[] = ['style' => in_array($hook['style'] ?? null, self::HOOK_STYLES, true) ? $hook['style'] : 'question', 'text' => $text];
            }
        }

        return [
            'title' => mb_substr(trim((string) ($data['title'] ?? '')), 0, 200),
            'hooks' => $hooks,
            'characters' => $characters,
            'art_style' => mb_substr(trim((string) ($data['art_style'] ?? '')), 0, 300),
            'scenes' => array_slice($scenes, 0, 15),
            'caption' => trim((string) ($data['caption'] ?? '')),
            'hashtags' => array_values(array_slice(array_map('strval', (array) ($data['hashtags'] ?? [])), 0, 10)),
        ];
    }

    /**
     * Khung trang manga: chỉ nhận nhân vật có trong thư viện và mã tư thế có thật của nhân vật đó (sai mã thì dùng mặt thường E1).
     *
     * @return list<array{character: string, pose: string, camera: string}>
     */
    public static function panels(array $panels): array
    {
        $result = [];
        foreach ($panels as $panel) {
            $key = DragonBall::keyFor((string) ($panel['character'] ?? '')) ?? (isset(DragonBall::CHARACTERS[$panel['character'] ?? '']) ? $panel['character'] : null);
            if ($key === null || count($result) >= self::MAX_PANELS) {
                continue;
            }
            $pose = strtoupper(trim((string) ($panel['pose'] ?? '')));
            $result[] = [
                'character' => $key,
                'pose' => DragonBall::pose($key, $pose) !== null ? $pose : 'E1',
                'camera' => mb_substr(trim((string) ($panel['camera'] ?? '')), 0, 80),
            ];
        }

        return $result;
    }

    /**
     * @param  list<int>  $allowed
     * @param  list<int>  $used  Khung đã dùng ở cảnh trước (cập nhật tại chỗ).
     * @return list<int>
     */
    private static function mangaPanels(array $ids, array $allowed, array &$used): array
    {
        $result = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if (in_array($id, $allowed, true) && ! in_array($id, $used, true) && count($result) < self::MAX_PANELS) {
                $result[] = $id;
                $used[] = $id;
            }
        }

        return $result;
    }

    /**
     * Khung mặc định khi Claude không chọn: tối đa 2 nhân vật thư viện có trong cảnh, nhân vật đầu với biểu cảm của cảnh.
     *
     * @param  list<string>  $characters
     * @return list<array{character: string, pose: string, camera: string}>
     */
    public static function panelsFor(array $characters, ?string $expression): array
    {
        $keys = array_slice(array_values(array_unique(array_filter(array_map(fn ($name) => DragonBall::keyFor((string) $name), $characters)))), 0, 2);

        return array_map(fn ($key, $i) => ['character' => $key, 'pose' => $i === 0 && $expression ? $expression : 'E1', 'camera' => ''], $keys, array_keys($keys));
    }

    /** Kết quả mẫu cho driver fake (test, dev không có Claude). */
    private static function sample(TopicShort $short, array $panelIds = []): array
    {
        return [
            'title' => "Short: {$short->topic}",
            'characters' => [
                ['name' => 'Goku', 'look' => 'spiky black hair, orange gi with blue undershirt and wristbands'],
                ['name' => 'Frieza', 'look' => 'final form: white and purple smooth body, red eyes, tail'],
            ],
            'art_style' => 'Dragon Ball, Akira Toriyama character design',
            'hooks' => [
                ['style' => 'question', 'text' => 'Bạn có biết khoảnh khắc này làm cả thế hệ nổi da gà?'],
                ['style' => 'shock', 'text' => 'Một cơn giận đã thay đổi cả vũ trụ Ngọc Rồng.'],
                ['style' => 'number', 'text' => 'Chỉ một giây, sức mạnh tăng gấp năm mươi lần.'],
            ],
            'scenes' => [
                ['narration' => 'Bạn có biết khoảnh khắc này làm cả thế hệ nổi da gà?', 'on_screen_text' => 'Khoảnh khắc huyền thoại', 'image_query' => 'Goku Super Saiyan Namek', 'image_prompt' => 'Goku powering up with a glowing golden aura, stormy sky, low angle', 'characters' => ['Goku'], 'expression' => 'E8', 'mood' => 'epic', 'panels' => [['character' => 'Goku', 'pose' => 'A5', 'camera' => 'low angle full body']], 'manga_panels' => array_slice($panelIds, 0, 2), 'fact_ids' => ['F1']],
                ['narration' => 'Kẻ thù vừa làm điều không thể tha thứ.', 'on_screen_text' => 'Giới hạn bị phá vỡ', 'image_query' => 'Frieza Namek', 'image_prompt' => 'Frieza smirking, green planet Namek, close-up', 'characters' => ['Frieza'], 'expression' => 'E7', 'mood' => 'tense', 'panels' => [['character' => 'Frieza', 'pose' => 'E7', 'camera' => 'close-up'], ['character' => 'Goku', 'pose' => 'E3', 'camera' => 'close-up']], 'manga_panels' => [], 'fact_ids' => ['F2']],
                ['narration' => 'Và cơn giận ấy đã thay đổi tất cả. Bạn nhớ cảnh này không?', 'on_screen_text' => 'Bùng nổ', 'image_query' => 'Super Saiyan transformation', 'image_prompt' => 'Goku with golden hair rising, lightning, facing Frieza', 'characters' => ['Goku', 'Frieza'], 'expression' => 'E3', 'mood' => 'epic', 'panels' => [], 'manga_panels' => [], 'fact_ids' => []],
            ],
            'caption' => 'Khoảnh khắc làm nên huyền thoại.',
            'hashtags' => ['#anime', '#shorts'],
        ];
    }
}
