<?php

namespace App\Services\Film;

use App\Services\Script\ScriptWriterException;

/**
 * Claude đề xuất vài ý tưởng MV khác nhau từ lời bài hát, tên bài, phong cách, khung hình (trước khi tạo MV).
 * Người dùng chọn một ý tưởng để điền sẵn ô ý tưởng và ca sĩ (vẫn sửa được).
 */
final class MusicVideoIdeas
{
    public const COUNT = 3;

    public const SYSTEM = <<<'PROMPT'
    You are a music video director pitching concepts for an original official music video made entirely with AI image and image-to-video tools.
    From the song title, the lyrics and the chosen look, pitch distinct concepts as JSON.

    Rules:
    - Each concept is clearly different (for example: a narrative story, a pure performance concept, a symbolic / surreal concept);
      all follow the meaning and emotion of the lyrics.
    - Write in Vietnamese (the creator is Vietnamese), short and concrete.
    - title: a short name for the concept. message: the core message in one sentence. story: 3–5 sentences from intro to outro,
      saying what happens in the verses and in the chorus. mood: colors, light and pacing in one sentence.
    - singer: the look of the singer (age range, hair, outfit with colors, one signature item), 1–2 sentences, an original person (no celebrity).
    - locations: 2–4 places, a few words each.
    - Feasible with AI video: few characters (at most 2 per shot), simple actions, no crowds, no on-screen text, no dangerous stunts.
    - Original work only: no real celebrities, no copyrighted characters, no brand logos.
    PROMPT;

    public static function schema(): array
    {
        $string = ['type' => 'string'];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['ideas'],
            'properties' => [
                'ideas' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['title', 'message', 'story', 'mood', 'singer', 'locations'],
                    'properties' => [
                        'title' => $string, 'message' => $string, 'story' => $string, 'mood' => $string, 'singer' => $string,
                        'locations' => ['type' => 'array', 'items' => $string],
                    ],
                ]],
            ],
        ];
    }

    /** @param array{song_name?: ?string, song_brief?: ?string, lyrics?: ?string, style?: ?string, aspect_ratio?: ?string, singer?: ?string, hint?: ?string, previous?: list<string>} $input */
    public static function text(array $input): string
    {
        $previous = collect($input['previous'] ?? [])->filter()->map(fn ($t) => "- {$t}")->implode("\n");

        return 'Song title: '.(trim((string) ($input['song_name'] ?? '')) ?: 'unknown')."\n"
            .'Look: '.(trim((string) ($input['style'] ?? '')) ?: 'cinematic')."\n"
            .'Frame: '.(($input['aspect_ratio'] ?? '16:9') === '9:16' ? 'vertical 9:16 (TikTok, Reels, Shorts)' : 'horizontal 16:9 (YouTube)')."\n\n"
            .(filled($input['song_brief'] ?? null) ? "Song analysis:\n".trim($input['song_brief'])."\n\n" : '')
            .(filled($input['lyrics'] ?? null) ? "Lyrics:\n".trim($input['lyrics'])."\n\n" : "No lyrics given: base the concepts on the title and the look.\n\n")
            .(filled($input['singer'] ?? null) ? 'The creator already described the singer (keep this look in every concept): '.trim($input['singer'])."\n\n" : '')
            .(filled($input['hint'] ?? null) ? 'Notes from the creator: '.trim($input['hint'])."\n\n" : '')
            .($previous !== '' ? "Already suggested (pitch different concepts):\n{$previous}\n\n" : '')
            .'Pitch exactly '.self::COUNT.' concepts.';
    }

    /**
     * @return list<array{title: string, message: string, story: string, mood: string, singer: string, locations: list<string>}>
     *
     * @throws ScriptWriterException
     */
    public static function suggest(array $input, string $logContext): array
    {
        $data = FilmClaude::json(self::SYSTEM, self::text($input), self::schema(), 'film_plan', $logContext, 6000, fn () => self::sample());
        $text = fn ($value, int $max) => mb_substr(trim((string) $value), 0, $max);
        $ideas = collect((array) ($data['ideas'] ?? []))->filter(fn ($i) => is_array($i) && filled($i['story'] ?? null))->take(self::COUNT)->map(fn ($i) => [
            'title' => $text($i['title'] ?? '', 120),
            'message' => $text($i['message'] ?? '', 300),
            'story' => $text($i['story'] ?? '', 2000),
            'mood' => $text($i['mood'] ?? '', 300),
            'singer' => $text($i['singer'] ?? '', 600),
            'locations' => array_values(array_filter(array_map(fn ($l) => $text($l, 100), array_slice((array) ($i['locations'] ?? []), 0, 4)))),
        ])->values()->all();
        if ($ideas === []) {
            throw new ScriptWriterException('Claude không trả về ý tưởng nào.');
        }

        return $ideas;
    }

    /** Mẫu khi chưa kết nối Claude (và trong test). */
    private static function sample(): array
    {
        return ['ideas' => [
            ['title' => 'Mưa đêm neon', 'message' => 'Buông bỏ để thấy mình sáng lên.', 'story' => 'Cô gái đi một mình trong phố mưa đêm, nhớ người cũ. Điệp khúc đèn neon bừng sáng theo bước chân. Cuối MV cô đứng trên sân thượng mỉm cười.', 'mood' => 'Xanh ngọc và hồng tím, chậm ở verse, sôi động ở điệp khúc.', 'singer' => 'Cô gái khoảng 24 tuổi, tóc ngang vai, áo khoác da đen, dây chuyền bạc.', 'locations' => ['Phố mưa đêm', 'Sân thượng']],
            ['title' => 'Phòng thu một mình', 'message' => 'Âm nhạc là nơi trú ẩn.', 'story' => 'Ca sĩ hát trong phòng thu tối, mỗi điệp khúc ánh sáng đổi màu theo cảm xúc. Kết bằng cảnh cửa sổ mở ra bình minh.', 'mood' => 'Ánh đèn ấm, khói nhẹ, máy quay trôi chậm.', 'singer' => 'Cô gái khoảng 24 tuổi, tóc ngang vai, áo len trắng rộng.', 'locations' => ['Phòng thu', 'Cửa sổ bình minh']],
            ['title' => 'Giấc mơ giấy', 'message' => 'Ký ức mong manh như giấy.', 'story' => 'Những tấm ảnh cũ hoá thành chim giấy bay quanh ca sĩ. Điệp khúc cả căn phòng ngập chim giấy. Cuối MV chim bay ra bầu trời.', 'mood' => 'Màu pastel, ánh sáng mềm, siêu thực.', 'singer' => 'Cô gái khoảng 24 tuổi, tóc ngang vai, váy trắng, kẹp tóc hình chim.', 'locations' => ['Căn phòng cũ', 'Bầu trời hoàng hôn']],
        ]];
    }
}
