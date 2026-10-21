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
    - image_query: 2–5 English keywords to find a matching screenshot or artwork on a fan wiki by file name (character name + form + event/place, e.g. "Goku Super Saiyan Namek"). Use official character names here.
    - image_prompt: English prompt for an AI image generator to draw the same picture as an original anime-style illustration, vertical 9:16. Describe appearance, pose, setting, lighting and mood; do NOT use franchise or character names.
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
            'required' => ['title', 'scenes', 'caption', 'hashtags'],
            'properties' => [
                'title' => $string,
                'scenes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['narration', 'on_screen_text', 'image_query', 'image_prompt'],
                        'properties' => [
                            'narration' => $string,
                            'on_screen_text' => $string,
                            'image_query' => $string,
                            'image_prompt' => $string,
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
            ];
        }
        if (count($scenes) < 2) {
            throw new ScriptWriterException('Claude trả về quá ít cảnh, thử viết lại.');
        }

        return [
            'title' => mb_substr(trim((string) ($data['title'] ?? '')), 0, 200),
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
            'scenes' => [
                ['narration' => 'Bạn có biết khoảnh khắc này làm cả thế hệ nổi da gà?', 'on_screen_text' => 'Khoảnh khắc huyền thoại', 'image_query' => 'Goku Super Saiyan Namek', 'image_prompt' => 'anime style, a spiky-haired warrior with glowing golden aura, stormy sky, vertical 9:16'],
                ['narration' => 'Kẻ thù vừa làm điều không thể tha thứ.', 'on_screen_text' => 'Giới hạn bị phá vỡ', 'image_query' => 'Frieza Namek', 'image_prompt' => 'anime style, a pale alien tyrant smirking, green planet, vertical 9:16'],
                ['narration' => 'Và cơn giận ấy đã thay đổi tất cả. Bạn nhớ cảnh này không?', 'on_screen_text' => 'Bùng nổ', 'image_query' => 'Super Saiyan transformation', 'image_prompt' => 'anime style, golden hair rising, lightning, vertical 9:16'],
            ],
            'caption' => 'Khoảnh khắc làm nên huyền thoại.',
            'hashtags' => ['#anime', '#shorts'],
        ];
    }
}
