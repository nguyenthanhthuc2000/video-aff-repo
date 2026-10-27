<?php

namespace App\Services\Manga;

use App\Models\TopicShort;
use App\Models\TopicShortScene;
use App\Services\Film\FilmClaude;
use App\Services\Script\ScriptWriterException;

/**
 * Claude tìm ảnh thật trên mạng cho từng cảnh (WebSearch / WebFetch): tranh màu nhân vật, khung manga tô màu, ảnh anime đúng khoảnh khắc.
 * Trả địa chỉ file ảnh trực tiếp; máy tải về, lưu vào Kho khung manga và gắn vào cảnh.
 */
final class SceneImageFinder
{
    /** Số ảnh tối đa mỗi cảnh. */
    public const PER_SCENE = 4;

    public const SYSTEM = <<<'PROMPT'
    You find pictures for the scenes of a vertical anime recap short (Dragon Ball first), like popular Vietnamese anime Shorts channels do:
    colourful character art and key art, coloured manga panels, and anime screenshots of the exact moment the narration talks about.

    How:
    - Use web search and web fetch. Good sources: Dragon Ball Wiki images (static.wikia.nocookie.net), Reddit (i.redd.it), DeviantArt, Pinterest (i.pinimg.com), Twitter/X (pbs.twimg.com), Kanzenshuu, Zerochan, Wallpaper sites.
    - Return DIRECT image file URLs (the jpg / png / webp file itself, as found in the page source or og:image), never an HTML page URL. Prefer the largest version (on static.wikia.nocookie.net drop "/scale-to-width-down/..." so the original is used).
    - Each picture must clearly show what the scene is about: the right character(s), the right form / transformation, the right moment. Skip pictures with big watermarks, memes, merch photos, cosplay, AI-generated art, or tiny thumbnails.
    - Up to 4 pictures per scene, best first, all different. Vary them (close-up face, full body, the action) so the editor can cut every 2–3 seconds.
    - description: one Vietnamese sentence saying what the picture shows. kind: "art", "manga" or "anime". characters: official English names visible.
    PROMPT;

    /**
     * @param  iterable<TopicShortScene>  $scenes
     * @return array<int, list<array{url: string, description: string, kind: string, characters: list<string>}>> theo id cảnh
     *
     * @throws ScriptWriterException
     */
    public static function find(TopicShort $short, iterable $scenes): array
    {
        $scenes = collect($scenes)->values();
        $text = "Topic: {$short->topic}\nScenes (number: narration [characters]):\n"
            .$scenes->map(fn (TopicShortScene $scene, $i) => ($i + 1).': '.$scene->narration.' ['.implode(', ', $scene->characters ?? []).']')->implode("\n");
        $data = FilmClaude::json(self::SYSTEM, $text, self::schema(), 'topic_research', "topic_research:images:{$short->id}", 6000, fn () => self::sample($scenes->count()), web: true);

        $result = [];
        foreach ($data['scenes'] ?? [] as $item) {
            $scene = $scenes->get(((int) ($item['number'] ?? 0)) - 1);
            if (! $scene) {
                continue;
            }
            $images = [];
            foreach ((array) ($item['images'] ?? []) as $image) {
                $url = trim((string) ($image['url'] ?? ''));
                if (str_starts_with($url, 'http') && count($images) < self::PER_SCENE && ! in_array($url, array_column($images, 'url'), true)) {
                    $images[] = [
                        'url' => mb_substr($url, 0, 1000),
                        'description' => mb_substr(trim((string) ($image['description'] ?? '')), 0, 500),
                        'kind' => in_array($image['kind'] ?? null, ['art', 'manga', 'anime'], true) ? $image['kind'] : 'art',
                        'characters' => array_values(array_map('strval', (array) ($image['characters'] ?? []))),
                    ];
                }
            }
            $result[$scene->id] = $images;
        }

        return $result;
    }

    private static function schema(): array
    {
        $string = ['type' => 'string'];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['scenes'],
            'properties' => [
                'scenes' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['number', 'images'],
                    'properties' => [
                        'number' => ['type' => 'integer'],
                        'images' => ['type' => 'array', 'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['url', 'description', 'kind', 'characters'],
                            'properties' => [
                                'url' => $string,
                                'description' => $string,
                                'kind' => ['type' => 'string', 'enum' => ['art', 'manga', 'anime']],
                                'characters' => ['type' => 'array', 'items' => $string],
                            ],
                        ]],
                    ],
                ]],
            ],
        ];
    }

    private static function sample(int $count): array
    {
        return ['scenes' => array_map(fn ($i) => ['number' => $i, 'images' => [
            ['url' => "https://images.example.com/scene-{$i}-a.png", 'description' => 'Goku hoá Super Saiyan trên Namek.', 'kind' => 'anime', 'characters' => ['Goku']],
            ['url' => "https://images.example.com/scene-{$i}-b.png", 'description' => 'Frieza cười nhếch mép.', 'kind' => 'art', 'characters' => ['Frieza']],
        ]], range(1, $count))];
    }
}
