<?php

namespace App\Services\Film;

use App\Models\Series;
use App\Models\SeriesCharacter;
use App\Services\Script\ScriptWriterException;
use App\Support\Media;

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
    - voice_type: kid_boy, kid_girl, adult_male, adult_female, elder (old man), elder_female (old woman) or creature.
    - 3–6 recurring locations, each described visually in 20–50 words (no characters in the description).
    - Episode synopsis: 2–4 sentences with the beginning, the problem and how it is solved. lesson: one short sentence.
    - title: a short catchy series name. logline: one sentence. premise: 2–3 sentences. world: where and when the story happens.
      tone: the feel of the show. themes: 3–5 recurring themes.
    PROMPT;

    /** Truyện ngắn không thoại khung dọc (kể bằng hành động, biểu cảm, âm thanh) cho khán giả quốc tế. */
    public const SYSTEM_SHORT = <<<'PROMPT'
    You are the creative director of an original short-form animated series with NO dialogue, made for TikTok, Facebook Reels and
    YouTube Shorts (vertical 9:16, 20–35 seconds per episode) and an international audience. From the creator's idea, write the series plan as JSON.

    Rules:
    - Everything (titles, names, descriptions) in simple, natural English.
    - Original work only: never copy names, looks or signature items of existing copyrighted characters or shows, and avoid
      recognizably derivative designs. Following a general art style is fine.
    - Stories work with the sound off: told through actions, expressions, staging, objects, music and sound effects. Humor and warmth
      (friendship, kindness, misunderstandings, pranks, small adventures); no repetitive tragedy, no fake animal cruelty, no manipulation.
    - Every episode has a distinctive conflict and a satisfying outcome; vary the rhythm and the kind of ending, no near-duplicate plots.
    - Characters: keep the creator's characters exactly (names, species, colors, accessories). "appearance" is a fixed visual description reused
      in every image prompt: species, body size and proportions, fur / skin colors and pattern, eye color, ears / tail, outfit or accessories
      with colors. 35–70 words, no personality words, nothing that changes between scenes. Characters must look clearly different.
    - voice_type: use creature for animals (they never speak).
    - 3–6 recurring locations, each described visually in 20–50 words (no characters in the description).
    - Episode synopsis: 2–3 sentences: the hook, the problem and the twist / payoff. kind: heartwarming, funny, magical or adventure.
      lesson: the feeling or small truth the episode leaves (one short sentence).
    - title: the series name. logline: one sentence. premise: 2–3 sentences. world: where the stories happen. tone: the feel of the show.
      themes: 3–5 recurring themes.
    PROMPT;

    /** MV ca nhạc: kế hoạch một video theo bài hát (ý tưởng hình ảnh, ca sĩ / nhân vật, bối cảnh). */
    public const SYSTEM_MV = <<<'PROMPT'
    You are the director of an original official music video made entirely with AI image and image-to-video tools.
    From the artist's idea, the song and its lyrics, write the plan of the music video as JSON.

    Rules:
    - Everything (titles, names, descriptions) in natural English, even when the song or the idea is in another language.
    - Original work only: no real celebrities, no copyrighted characters, no brand logos. Following a general art style is fine.
    - The concept follows the meaning and emotion of the lyrics: one clear visual story or performance concept, strong recurring motifs,
      and a visual change between verses and the chorus.
    - Characters: the singer (role main) first, then 0–3 other characters the story needs. Keep the creator's characters exactly when given.
      "appearance" is a fixed visual description reused in every image prompt: age range, build, face, skin tone, hair, outfit with colors,
      one or two signature items. 35–70 words, nothing that changes between shots. voice_type: adult_male, adult_female or the closest type.
    - 2–6 locations, each described visually in 20–50 words (light, colors, time of day; no characters in the description).
    - Exactly one episode: the music video itself. title: the song title + " (Official Music Video)". synopsis: the story of the video
      in 3–5 sentences from intro to outro. lesson: the feeling the video leaves (one short sentence).
    - title (top level): the song title. logline: the concept in one sentence. premise: the story in 2–3 sentences. world: where and when.
      tone: mood, colors and pacing. themes: 3–5 visual motifs.
    PROMPT;

    public static function schema(bool $short = false): array
    {
        $string = ['type' => 'string'];
        $episode = ['title' => $string, 'synopsis' => $string, 'lesson' => $string, ...($short ? ['kind' => ['type' => 'string', 'enum' => array_keys(Series::KINDS)]] : [])];

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
                    'required' => array_keys($episode),
                    'properties' => $episode,
                ]],
            ],
        ];
    }

    public static function userText(Series $series): string
    {
        if ($series->silent()) {
            return self::shortText($series);
        }
        if ($series->musicVideo()) {
            return self::musicVideoText($series);
        }
        $length = $series->episode_seconds < 60 ? "{$series->episode_seconds} seconds (a very short episode: one tiny situation, a quick funny payoff)" : round($series->episode_seconds / 60, 1).' minutes';
        $seeds = collect($series->seed_characters ?? [])->values()->map(fn ($c, $i) => '- Image '.($i + 1).": {$c['name']}".(filled($c['note'] ?? null) ? " ({$c['note']})" : ''))->implode("\n");

        return "Idea from the creator (may be in Vietnamese, write the plan in English):\n{$series->idea}\n\n"
            .'Genre: '.(Series::GENRES[$series->genre] ?? $series->genre)." ({$series->genre}).\n"
            .AgeRules::prompt($series->age_min, $series->age_max)."\n"
            ."Art style (for the appearance descriptions): {$series->style}.\n"
            ."Episodes: exactly {$series->episode_count}, about {$length} each.\n\n"
            .($seeds !== '' ? "The creator already has these characters; the attached images show them in order:\n{$seeds}\n"
                .'Include each of them with exactly this name. Write their "appearance" strictly from their image (what you see: body, colors, '
                .'clothes, items), not from imagination. Add other characters only if the story needs them.'."\n\n" : '')
            .'Write the series plan in the required format.';
    }

    private static function shortText(Series $series): string
    {
        $seeds = collect($series->seed_characters ?? [])->values()->map(fn ($c, $i) => '- Image '.($i + 1).": {$c['name']}".(filled($c['note'] ?? null) ? " ({$c['note']})" : ''))->implode("\n");
        // Tên / ý tưởng còn là chữ tạm của file mẫu ("[Episode Title]", "[...]"): để Claude tự đặt.
        $list = collect($series->seed_episodes ?? [])->values()->map(fn ($e, $i) => ($i + 1).'. '.(EpisodeTitler::placeholder($e['title']) ? '(no title yet: invent a catchy one)' : $e['title'])
            .(filled($e['idea'] ?? null) && ! EpisodeTitler::placeholder($e['idea']) ? ": {$e['idea']}" : '')
            .(filled($e['kind'] ?? null) ? " [{$e['kind']}]" : ''))->implode("\n");

        return "Idea and series bible from the creator (may be in Vietnamese, write the plan in English):\n{$series->idea}\n\n"
            .'Audience: '.($series->audience ?: Series::SHORT_AUDIENCE).".\n"
            ."Art style (for the appearance descriptions): {$series->style}.\n"
            ."Format: vertical 9:16, about {$series->episode_seconds} seconds per episode, no dialogue.\n"
            ."Episodes: exactly {$series->episode_count}.\n\n"
            .($list !== '' ? 'The creator already chose the episodes, in this order. Keep exactly these titles and this order; write each synopsis '
                ."from its idea (you may add the twist and the ending) and keep its kind when given:\n{$list}\n\n" : '')
            .($seeds !== '' ? "The creator already has these characters; the attached images show them in order:\n{$seeds}\n"
                .'Write their "appearance" strictly from their image.'."\n\n" : '')
            .'Write the series plan in the required format.';
    }

    private static function musicVideoText(Series $series): string
    {
        $seeds = collect($series->seed_characters ?? [])->values()->map(fn ($c, $i) => '- Image '.($i + 1).": {$c['name']}".(filled($c['note'] ?? null) ? " ({$c['note']})" : ''))->implode("\n");
        [$from, $to] = $series->songRange();
        $options = $series->mv_options ?? [];
        $lyrics = filled($series->lyrics) ? $series->lyrics : ($series->song?->lyrics ?: collect($series->song?->vocal_analysis['words'] ?? [])->pluck('text')->implode(' '));

        return "Idea from the artist (may be in Vietnamese, write the plan in English):\n{$series->idea}\n\n"
            .'Song: '.($series->song?->name ?? 'untitled').', excerpt of '.round(($to - $from) / 1000).' seconds.'."\n"
            .'Audience: '.($series->audience ?: Series::MV_AUDIENCE).".\n"
            ."Art style (for the appearance descriptions): {$series->style}.\n"
            ."Frame: {$series->frameLine()}.\n\n"
            .(filled($lyrics) ? "Lyrics:\n{$lyrics}\n\n" : "No lyrics given: base the concept on the idea and the mood.\n\n")
            .($seeds !== '' ? "The artist already has these characters; the attached images show them in order:\n{$seeds}\n"
                .'Include each of them with exactly this name. Write their "appearance" strictly from their image.'."\n\n" : '')
            .($seeds === '' && filled($options['singer'] ?? null) ? 'The singer (main character) is named '.($options['singer_name'] ?? 'Singer')
                .". Base the singer's appearance on this description from the artist:\n{$options['singer']}\n\n" : '')
            .match ($series->lipSyncMode()) {
                'full' => "The singer lip-syncs the whole song: plan many performance shots where the singer sings to the camera.\n\n",
                'none' => "No lip sync in this video: the singer never sings on camera; tell the song through story, mood and b-roll.\n\n",
                default => "The singer lip-syncs only the chorus; verses are told through story shots and b-roll.\n\n",
            }
        .'Write the music video plan in the required format.';
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
    public static function normalize(mixed $data, int $episodeCount, array $seedEpisodes = []): array
    {
        if (! is_array($data) || empty($data['characters']) || empty($data['episodes'])) {
            throw new ScriptWriterException('Claude không trả về kế hoạch bộ phim.');
        }
        $text = fn ($value, int $max) => mb_substr(trim((string) $value), 0, $max);

        // Mỗi nhân vật một giọng riêng (không trùng nhau, không trùng người dẫn chuyện).
        $used = [Voices::NARRATOR['voice']];
        $characters = collect($data['characters'])->take(8)->map(function ($c) use (&$used, $text) {
            $voice = Voices::forType((string) ($c['voice_type'] ?? ''), $used);
            $used[] = $voice['voice'];

            return [
                'name' => $text($c['name'] ?? '', 80),
                'role' => isset(SeriesCharacter::ROLES[$c['role'] ?? '']) ? $c['role'] : 'side',
                'age' => isset($c['age']) ? max(0, min(120, (int) $c['age'])) : null,
                'personality' => $text($c['personality'] ?? '', 1000),
                'appearance' => $text($c['appearance'] ?? '', 1500),
                'voice' => $voice,
            ];
        })->filter(fn ($c) => $c['name'] !== '' && $c['appearance'] !== '')->values()->all();

        $locations = collect($data['locations'] ?? [])->take(8)->map(fn ($l) => [
            'name' => $text($l['name'] ?? '', 80),
            'description' => $text($l['description'] ?? '', 1000),
        ])->filter(fn ($l) => $l['name'] !== '' && $l['description'] !== '')->values()->all();

        $episodes = collect($data['episodes'])->take($episodeCount)->map(fn ($e) => [
            'title' => $text($e['title'] ?? '', 200),
            'synopsis' => $text($e['synopsis'] ?? '', 2000),
            'lesson' => $text($e['lesson'] ?? '', 500),
            'kind' => EpisodeList::kind($e['kind'] ?? null),
        ])->filter(fn ($e) => $e['title'] !== '' && $e['synopsis'] !== '')->values()->all();
        // Danh sách tập có sẵn: giữ đúng tên, thể loại, kịch bản có sẵn theo thứ tự; Claude chỉ viết tóm tắt. Claude trả thiếu thì dùng ý tưởng.
        foreach (array_values($seedEpisodes) as $i => $seed) {
            $episodes[$i] = [
                'title' => EpisodeTitler::placeholder($seed['title']) && filled($episodes[$i]['title'] ?? null) && ! EpisodeTitler::placeholder($episodes[$i]['title'])
                    ? $episodes[$i]['title'] : $text($seed['title'], 200),
                'synopsis' => $episodes[$i]['synopsis'] ?? ($text($seed['idea'] ?? '', 2000) ?: $text($seed['title'], 200)),
                'lesson' => $episodes[$i]['lesson'] ?? '',
                'kind' => $seed['kind'] ?? ($episodes[$i]['kind'] ?? null),
                'brief' => filled($seed['brief'] ?? null) ? $text($seed['brief'], 8000) : null,
            ];
        }
        $episodes = array_slice($episodes, 0, max($episodeCount, count($seedEpisodes)));

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
        $images = collect($series->seed_characters ?? [])->map(fn ($c) => ['data' => (string) Media::disk()->get($c['path']), 'media_type' => Media::disk()->mimeType($c['path']) ?: 'image/png'])
            ->filter(fn ($image) => $image['data'] !== '')->values()->all();

        return self::normalize(
            FilmClaude::json($series->musicVideo() ? self::SYSTEM_MV : ($series->silent() ? self::SYSTEM_SHORT : self::SYSTEM), self::userText($series), self::schema($series->silent()), 'film_plan', "film_plan:{$series->id}", 16000, fn () => self::sample($series), $images),
            $series->episode_count,
            $series->seed_episodes ?? [],
        );
    }

    /** Mẫu khi chưa kết nối Claude (và trong test). */
    public static function sample(Series $series): array
    {
        if ($series->silent()) {
            return self::sampleShort($series);
        }
        if ($series->musicVideo()) {
            return self::sampleMusicVideo($series);
        }

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

    private static function sampleMusicVideo(Series $series): array
    {
        return [
            'title' => $series->song?->name ?? 'Neon Rain',
            'logline' => 'A lonely singer walks through a rainy neon city and finds the light again.',
            'premise' => 'The singer remembers a lost love while walking at night. In the chorus the city lights up around her.',
            'world' => 'A modern city at night, rain and neon.',
            'tone' => 'Melancholic, then hopeful; teal and magenta neon; slow verses, energetic chorus.',
            'themes' => ['rain', 'neon reflections', 'an old photo'],
            'characters' => [
                ['name' => 'Lan', 'role' => 'main', 'age' => 24, 'personality' => 'Quiet, determined.', 'appearance' => 'A young woman in her mid-20s, slim, shoulder-length black hair with bangs, dark brown eyes, black leather jacket over a white t-shirt, silver necklace, black boots.', 'voice_type' => 'adult_female'],
            ],
            'locations' => [
                ['name' => 'Neon street', 'description' => 'A narrow city street at night after rain, wet asphalt reflecting teal and magenta neon signs without readable text.'],
                ['name' => 'Rooftop', 'description' => 'A rooftop above the city at night with fairy lights, puddles and the skyline glowing behind.'],
            ],
            'episodes' => [['title' => ($series->song?->name ?? 'Neon Rain').' (Official Music Video)', 'synopsis' => 'Lan walks through the rain remembering. The chorus lights up the city. She ends on the rooftop smiling.', 'lesson' => 'Hope after loss.']],
        ];
    }

    private static function sampleShort(Series $series): array
    {
        return [
            'title' => "Milo's Little World",
            'logline' => 'A tiny orange kitten and his friends turn small troubles into warm, funny adventures.',
            'premise' => 'Milo lives in a cozy animal village with his best friend Bobo and clever Luna. Every short tells one small story without words.',
            'world' => 'A cozy colorful animal village with a park, a bakery street and a little pond.',
            'tone' => 'Warm, funny and cinematic.',
            'themes' => ['friendship', 'kindness', 'misunderstandings'],
            'characters' => [
                ['name' => 'Milo', 'role' => 'main', 'age' => 1, 'personality' => 'Kind, curious, a bit clumsy.', 'appearance' => 'A tiny orange tabby kitten with slightly oversized ears, emerald-green eyes, soft striped fur, white chest patch and a teal knitted scarf.', 'voice_type' => 'creature'],
                ['name' => 'Bobo', 'role' => 'friend', 'age' => 2, 'personality' => 'Loyal, hungry, playful.', 'appearance' => 'A small cream-colored corgi with short legs, big fluffy ears, a white muzzle, brown eyes and a blue collar with a round tag.', 'voice_type' => 'creature'],
            ],
            'locations' => [
                ['name' => 'Village park', 'description' => 'A sunny park with a wooden bench, round green trees, flower beds and a gravel path.'],
                ['name' => 'Cozy porch', 'description' => 'A small wooden porch with a round table, a white plate, potted plants and warm sunlight.'],
            ],
            'episodes' => collect(range(1, $series->episode_count))->map(fn (int $n) => [
                'title' => "Small Adventure #{$n}",
                'synopsis' => 'Milo finds a small problem, makes a kind choice and gets a sweet surprise.',
                'lesson' => 'Small kindness makes big memories.',
                'kind' => array_keys(Series::KINDS)[$n % 4],
            ])->all(),
        ];
    }
}
