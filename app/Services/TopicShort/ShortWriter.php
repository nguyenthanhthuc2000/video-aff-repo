<?php

namespace App\Services\TopicShort;

use App\Models\TopicShort;
use App\Services\Film\FilmClaude;
use App\Services\Script\ScriptWriterException;

/** Claude viết lời kể cho short theo chủ đề (anime / lore), chia cảnh kèm từ khoá tìm ảnh wiki và prompt vẽ ảnh. */
class ShortWriter
{
    /** Số từ đọc mỗi giây (ước lượng để Claude viết đúng độ dài). */
    public const WORDS_PER_SECOND = ['vi' => 3.0, 'en' => 2.5];

    public const SYSTEM = <<<'PROMPT'
    You write narration for vertical YouTube Shorts / TikTok videos (9:16, 30–60 seconds) that retell a famous moment, fact or piece of lore from anime and pop culture, in the style of a gripping storytelling channel.

    Rules:
    - Facts must match the original work (canon). Never invent events, quotes or numbers. If unsure about a detail, leave it out.
    - The first sentence is a hook that makes viewers stay (a question, a shocking fact, or the most intense moment), under 12 words.
    - Then tell the moment in order with rising tension, and end with a payoff plus a short question or call to comment.
    - Narration is spoken by a TTS voice: short, clear sentences, no emojis, no hashtags, no stage directions, numbers written as words when spoken naturally.
    - Write narration, on_screen_text, title and caption in the requested language. Write image_query and image_prompt in English.
    - Split into scenes of 4–7 seconds of speech each. Each scene shows one picture.
    - on_screen_text: at most 5 words, punchy, shown big on screen.
    - image_query: 2–3 English keywords, most important first, to find a matching screenshot or artwork on a fan wiki by file name (character name + form + event/place, e.g. "Goku Super Saiyan"). Use official character names here.
    - characters: the main recurring characters of the short (at most 5), each with the official English name and look: a precise description of their canonical appearance at this point of the story (face, hair style and color, eyes, body, outfit and colors, aura), so an image model can draw them on-model.
    - art_style: one line naming the original work and its visual style so the image model matches it (e.g. "Dragon Ball Z TV anime (1989), Toei cel animation, Akira Toriyama character design").
    - scene characters: names (exactly as in characters) of who appears in that scene's picture; empty if nobody.
    - image_prompt: English prompt for an AI image generator to draw that scene's picture, vertical 9:16. Name the characters by their official names and describe pose, expression, action, camera angle, setting, lighting and mood as in the original scene.
    - caption: 1–2 sentences for the post, plus hashtags in hashtags.
    PROMPT;

    /**
     * @return array{title: string, scenes: list<array{narration: string, on_screen_text: string, image_query: string, image_prompt: string}>, caption: string, hashtags: list<string>}
     *
     * @throws ScriptWriterException
     */
    public static function write(TopicShort $short): array
    {
        $language = $short->language === 'en' ? 'English' : 'Vietnamese (natural, as spoken by Vietnamese anime fans; keep well-known names like Goku, Super Saiyan as is)';
        $words = (int) round($short->target_seconds * self::WORDS_PER_SECOND[$short->language === 'en' ? 'en' : 'vi']);
        $text = "Topic: {$short->topic}\nLanguage: {$language}\nTarget length: about {$short->target_seconds} seconds of narration, about {$words} words in total.";

        $data = FilmClaude::json(self::SYSTEM, $text, self::schema(), 'topic_short', "topic_short:{$short->id}", 6000, fn () => self::sample($short));

        return self::normalize($data);
    }

    public static function schema(): array
    {
        $string = ['type' => 'string'];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['title', 'characters', 'art_style', 'scenes', 'caption', 'hashtags'],
            'properties' => [
                'title' => $string,
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
                        'required' => ['narration', 'on_screen_text', 'image_query', 'image_prompt', 'characters'],
                        'properties' => [
                            'narration' => $string,
                            'on_screen_text' => $string,
                            'image_query' => $string,
                            'image_prompt' => $string,
                            'characters' => ['type' => 'array', 'items' => $string],
                        ],
                    ],
                ],
                'caption' => $string,
                'hashtags' => ['type' => 'array', 'items' => $string],
            ],
        ];
    }

    /** @throws ScriptWriterException */
    public static function normalize(mixed $data): array
    {
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
                // Chỉ giữ tên có trong danh sách nhân vật (so không phân biệt hoa thường).
                'characters' => array_values(array_unique(array_filter(array_map(
                    fn ($name) => collect($names)->first(fn ($n) => mb_strtolower($n) === mb_strtolower(trim((string) $name))),
                    (array) ($scene['characters'] ?? []),
                )))),
            ];
        }
        if (count($scenes) < 2) {
            throw new ScriptWriterException('Claude trả về quá ít cảnh, thử viết lại.');
        }

        return [
            'title' => mb_substr(trim((string) ($data['title'] ?? '')), 0, 200),
            'characters' => $characters,
            'art_style' => mb_substr(trim((string) ($data['art_style'] ?? '')), 0, 300),
            'scenes' => array_slice($scenes, 0, 15),
            'caption' => trim((string) ($data['caption'] ?? '')),
            'hashtags' => array_values(array_slice(array_map('strval', (array) ($data['hashtags'] ?? [])), 0, 10)),
        ];
    }

    /** Kết quả mẫu cho driver fake (test, dev không có Claude). */
    private static function sample(TopicShort $short): array
    {
        return [
            'title' => "Short: {$short->topic}",
            'characters' => [
                ['name' => 'Goku', 'look' => 'spiky black hair, orange gi with blue undershirt and wristbands'],
                ['name' => 'Frieza', 'look' => 'final form: white and purple smooth body, red eyes, tail'],
            ],
            'art_style' => 'Dragon Ball Z TV anime, Toei cel animation',
            'scenes' => [
                ['narration' => 'Bạn có biết khoảnh khắc này làm cả thế hệ nổi da gà?', 'on_screen_text' => 'Khoảnh khắc huyền thoại', 'image_query' => 'Goku Super Saiyan Namek', 'image_prompt' => 'Goku powering up with a glowing golden aura, stormy sky, low angle', 'characters' => ['Goku']],
                ['narration' => 'Kẻ thù vừa làm điều không thể tha thứ.', 'on_screen_text' => 'Giới hạn bị phá vỡ', 'image_query' => 'Frieza Namek', 'image_prompt' => 'Frieza smirking, green planet Namek, close-up', 'characters' => ['Frieza']],
                ['narration' => 'Và cơn giận ấy đã thay đổi tất cả. Bạn nhớ cảnh này không?', 'on_screen_text' => 'Bùng nổ', 'image_query' => 'Super Saiyan transformation', 'image_prompt' => 'Goku with golden hair rising, lightning, facing Frieza', 'characters' => ['Goku', 'Frieza']],
            ],
            'caption' => 'Khoảnh khắc làm nên huyền thoại.',
            'hashtags' => ['#anime', '#shorts'],
        ];
    }
}
