<?php

namespace App\Services\Film;

use App\Models\Series;
use App\Models\SeriesCharacter;
use App\Services\Script\ScriptWriterException;

/**
 * Claude lên kế hoạch bộ phim từ ý tưởng: kinh thánh (logline, thế giới, giọng kể, chủ đề),
 * nhân vật (mô tả ngoại hình tiếng Anh cố định để vẽ giống nhau mọi shot), bối cảnh lặp lại, dàn ý từng tập.
 */
final class SeriesPlanner
{
    /** Tên nhân vật / thương hiệu có bản quyền: không nhận ý tưởng chép lại (chỉ được theo phong cách). */
    public const BLOCKED_NAMES = [
        'doraemon', 'nobita', 'conan', 'pikachu', 'pokemon', 'pokémon', 'mickey', 'minnie', 'elsa', 'frozen', 'peppa',
        'bluey', 'paw patrol', 'spider-man', 'spiderman', 'batman', 'superman', 'naruto', 'goku', 'dragon ball',
        'shin-chan', 'shin chan', 'hello kitty', 'sponge', 'mario', 'sonic', 'cocomelon', 'masha',
    ];

    public const SYSTEM = <<<'PROMPT'
    You are the head writer of an original English-language animated series for a YouTube kids channel with an international audience.
    From the user's idea, write the series plan as JSON.

    Rules:
    - Everything (titles, names, descriptions) in simple, natural English.
    - Original work only: never copy names, looks, catchphrases or signature items of existing copyrighted characters or shows.
      Following a general art style is fine.
    - Each episode is a complete story with one clear lesson or piece of knowledge (YouTube quality principles for kids content),
      and episodes must differ from each other (no near-duplicate plots).
    - 3–6 recurring characters. "appearance" is a fixed visual description reused in every image prompt: body type and height,
      face, hair (color, shape), eye color, outfit with colors, one or two signature items. 35–70 words, no personality words,
      nothing that changes between scenes. Characters must look clearly different from each other (colors and silhouettes).
    - voice_type: kid_boy, kid_girl, adult_male, adult_female, elder or creature.
    - 3–6 recurring locations, each described visually in 20–50 words (no characters in the description).
    - Episode synopsis: 2–4 sentences with the beginning, the problem and how it is solved. lesson: one short sentence.
    - title: a short catchy series name. logline: one sentence. premise: 2–3 sentences. world: where and when the story happens.
      tone: the feel of the show. themes: 3–5 recurring themes.
    PROMPT;

    public static function schema(): array
    {
        $string = ['type' => 'string'];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['title', 'logline', 'premise', 'world', 'tone', 'themes', 'characters', 'locations', 'episodes'],
            'properties' => [
                'title' => $string,
                'logline' => $string,
                'premise' => $string,
                'world' => $string,
                'tone' => $string,
                'themes' => ['type' => 'array', 'items' => $string],
                'characters' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['name', 'role', 'age', 'personality', 'appearance', 'voice_type'],
                    'properties' => [
                        'name' => $string,
                        'role' => ['type' => 'string', 'enum' => array_keys(SeriesCharacter::ROLES)],
                        'age' => ['type' => 'integer'],
                        'personality' => $string,
                        'appearance' => $string,
                        'voice_type' => ['type' => 'string', 'enum' => array_keys(Voices::TYPES)],
                    ],
                ]],
                'locations' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['name', 'description'],
                    'properties' => ['name' => $string, 'description' => $string],
                ]],
                'episodes' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['title', 'synopsis', 'lesson'],
                    'properties' => ['title' => $string, 'synopsis' => $string, 'lesson' => $string],
                ]],
            ],
        ];
    }

    public static function userText(Series $series): string
    {
        $minutes = round($series->episode_seconds / 60, 1);

        return "Idea from the creator (may be in Vietnamese, write the plan in English):\n{$series->idea}\n\n"
            .'Genre: '.(Series::GENRES[$series->genre] ?? $series->genre)." ({$series->genre}).\n"
            .AgeRules::prompt($series->age_min, $series->age_max)."\n"
            ."Art style (for the appearance descriptions): {$series->style}.\n"
            ."Episodes: exactly {$series->episode_count}, about {$minutes} minutes each.\n\n"
            .'Write the series plan in the required format.';
    }

    /** Ý tưởng có nhắc tên nhân vật / phim có bản quyền thì trả tên đó. */
    public static function blockedName(string $idea): ?string
    {
        $text = mb_strtolower($idea);
        foreach (self::BLOCKED_NAMES as $name) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/u', $text)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @return array{bible: array, characters: list<array>, locations: list<array>, episodes: list<array>}
     */
    public static function normalize(mixed $data, int $episodeCount): array
    {
        if (! is_array($data) || empty($data['characters']) || empty($data['episodes'])) {
            throw new ScriptWriterException('Claude không trả về kế hoạch bộ phim.');
        }
        $text = fn ($value, int $max) => mb_substr(trim((string) $value), 0, $max);

        $characters = collect($data['characters'])->take(8)->map(fn ($c) => [
            'name' => $text($c['name'] ?? '', 80),
            'role' => isset(SeriesCharacter::ROLES[$c['role'] ?? '']) ? $c['role'] : 'side',
            'age' => isset($c['age']) ? max(0, min(120, (int) $c['age'])) : null,
            'personality' => $text($c['personality'] ?? '', 1000),
            'appearance' => $text($c['appearance'] ?? '', 1500),
            'voice' => Voices::forType((string) ($c['voice_type'] ?? '')),
        ])->filter(fn ($c) => $c['name'] !== '' && $c['appearance'] !== '')->values()->all();

        $locations = collect($data['locations'] ?? [])->take(8)->map(fn ($l) => [
            'name' => $text($l['name'] ?? '', 80),
            'description' => $text($l['description'] ?? '', 1000),
        ])->filter(fn ($l) => $l['name'] !== '' && $l['description'] !== '')->values()->all();

        $episodes = collect($data['episodes'])->take($episodeCount)->map(fn ($e) => [
            'title' => $text($e['title'] ?? '', 200),
            'synopsis' => $text($e['synopsis'] ?? '', 2000),
            'lesson' => $text($e['lesson'] ?? '', 500),
        ])->filter(fn ($e) => $e['title'] !== '' && $e['synopsis'] !== '')->values()->all();

        if ($characters === [] || $episodes === []) {
            throw new ScriptWriterException('Kế hoạch bộ phim Claude trả về thiếu nhân vật hoặc tập.');
        }

        return [
            'bible' => [
                'title' => $text($data['title'] ?? '', 200),
                'logline' => $text($data['logline'] ?? '', 500),
                'premise' => $text($data['premise'] ?? '', 2000),
                'world' => $text($data['world'] ?? '', 2000),
                'tone' => $text($data['tone'] ?? '', 500),
                'themes' => array_values(array_filter(array_map(fn ($t) => $text($t, 120), (array) ($data['themes'] ?? [])))),
            ],
            'characters' => $characters,
            'locations' => $locations,
            'episodes' => $episodes,
        ];
    }

    /**
     * @return array{bible: array, characters: list<array>, locations: list<array>, episodes: list<array>}
     *
     * @throws ScriptWriterException
     */
    public static function write(Series $series): array
    {
        return self::normalize(
            FilmClaude::json(self::SYSTEM, self::userText($series), self::schema(), 'film_plan', "film_plan:{$series->id}", 16000, fn () => self::sample($series)),
            $series->episode_count,
        );
    }

    /** Mẫu khi chưa kết nối Claude (và trong test). */
    public static function sample(Series $series): array
    {
        return [
            'title' => 'Milo and the Mystery Club',
            'logline' => 'A curious boy and his friends solve small mysteries in their town.',
            'premise' => 'Milo runs a tiny detective club with his best friend Lily. Every week a new puzzle appears in Maple Town.',
            'world' => 'Maple Town, a sunny small town with a park, a school and a library.',
            'tone' => 'Warm, funny and curious.',
            'themes' => ['friendship', 'curiosity', 'honesty'],
            'characters' => [
                ['name' => 'Milo', 'role' => 'main', 'age' => 10, 'personality' => 'Curious, brave, a bit impatient.', 'appearance' => 'A 10-year-old boy, messy brown hair with a cowlick, green eyes, yellow hoodie with a magnifying-glass patch, blue jeans, red sneakers, orange backpack.', 'voice_type' => 'kid_boy'],
                ['name' => 'Lily', 'role' => 'friend', 'age' => 10, 'personality' => 'Smart, calm, loves books.', 'appearance' => 'A 10-year-old girl, black hair in two puffs, brown eyes, round glasses, purple dress with white dots, white socks, purple shoes.', 'voice_type' => 'kid_girl'],
                ['name' => 'Grandpa Joe', 'role' => 'mentor', 'age' => 70, 'personality' => 'Kind, tells old stories.', 'appearance' => 'A tall thin old man, white beard, bald head, green cardigan, brown trousers, wooden cane.', 'voice_type' => 'elder'],
            ],
            'locations' => [
                ['name' => 'Club treehouse', 'description' => 'A wooden treehouse with a rope ladder, a small window, a chalkboard full of clues.'],
                ['name' => 'Maple Town park', 'description' => 'A green park with a pond, a red bridge and tall maple trees.'],
                ['name' => 'Town library', 'description' => 'A cozy library with tall bookshelves, warm lamps and a big round reading rug.'],
            ],
            'episodes' => collect(range(1, $series->episode_count))->map(fn (int $n) => [
                'title' => "The Missing Clue #{$n}",
                'synopsis' => 'Something goes missing in Maple Town. Milo and Lily follow the clues and find out it was a misunderstanding.',
                'lesson' => 'Ask before you blame someone.',
            ])->all(),
        ];
    }
}
