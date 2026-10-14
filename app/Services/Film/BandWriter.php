<?php

namespace App\Services\Film;

use App\Models\VirtualBand;
use App\Services\Script\ScriptWriterException;

/** Claude nghĩ ra một ban nhạc ẢO từ thể loại + thời kỳ (+ ghi chú): tên, câu chuyện, thành viên với ngoại hình lúc trẻ và lúc già. */
final class BandWriter
{
    public const SYSTEM = <<<'PROMPT'
    You create FICTIONAL music acts for an AI music video channel. Return JSON for one original band (or solo artist).

    Rules:
    - Completely original: the name must not be, resemble or pun on any real band or artist; members are not based on real musicians.
    - lore: 2–3 sentences: where they come from, their rise, a signature trait fans love (written in English).
    - members: 1–5 people. name: a memorable stage name. role: instrument / position (e.g. "lead vocals", "lead guitar").
      look: the look at the band's peak era, 35–60 words: age, build, face, skin tone, hair, outfit with colors, one signature item;
      clearly different silhouettes between members. look_old: the same person today, aged accordingly (hair, wrinkles, clothes), 25–45 words.
    - genre and era: keep the requested ones, era as a decade or year range (e.g. "1970s").
    PROMPT;

    public static function schema(): array
    {
        $string = ['type' => 'string'];

        return [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['name', 'genre', 'era', 'lore', 'members'],
            'properties' => [
                'name' => $string, 'genre' => $string, 'era' => $string, 'lore' => $string,
                'members' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['name', 'role', 'look', 'look_old'],
                    'properties' => ['name' => $string, 'role' => $string, 'look' => $string, 'look_old' => $string],
                ]],
            ],
        ];
    }

    /** @throws ScriptWriterException */
    public static function write(VirtualBand $band, ?string $notes): array
    {
        $text = "Genre: {$band->genre}\nEra: {$band->era}\n".(filled($notes) ? "Creator's notes (may be in Vietnamese): {$notes}\n" : '').'Create the band.';
        $data = (array) FilmClaude::json(self::SYSTEM, $text, self::schema(), 'film_plan', "band:{$band->id}", 4000, fn () => self::sample($band));

        return self::normalize($data, $band);
    }

    public static function normalize(array $data, VirtualBand $band): array
    {
        $text = fn ($value, int $max) => mb_substr(trim((string) $value), 0, $max);
        $members = collect((array) ($data['members'] ?? []))->filter(fn ($m) => is_array($m) && filled($m['name'] ?? null) && filled($m['look'] ?? null))
            ->take(6)->map(fn ($m) => [
                'name' => $text($m['name'], 60), 'role' => $text($m['role'] ?? '', 60), 'look' => $text($m['look'], 600),
                'look_old' => $text($m['look_old'] ?? '', 500), 'image_path' => null,
            ])->values()->all();
        if ($members === [] || ! filled($data['name'] ?? null)) {
            throw new ScriptWriterException('Claude không trả về ban nhạc.');
        }

        return [
            'name' => $text($data['name'], 120),
            'genre' => $text($data['genre'] ?? $band->genre, 100) ?: $band->genre,
            'era' => $text($data['era'] ?? $band->era, 40) ?: $band->era,
            'lore' => $text($data['lore'] ?? '', 1500),
            'members' => $members,
        ];
    }

    private static function sample(VirtualBand $band): array
    {
        return [
            'name' => 'Velvet Thunder', 'genre' => $band->genre, 'era' => $band->era,
            'lore' => 'A hard rock band from a rainy harbor town who played sweaty basement clubs before one anthem made them famous.',
            'members' => [
                ['name' => 'Jax Rivera', 'role' => 'lead vocals', 'look' => 'Mid-20s man, lean, long curly black hair, open red satin shirt, leather pants, silver star necklace.', 'look_old' => 'Late 70s, long white curly hair, black leather jacket, same silver star necklace.'],
                ['name' => 'Mona Kade', 'role' => 'lead guitar', 'look' => 'Mid-20s woman, platinum shag haircut, white fringe jacket, flared jeans, cherry red guitar.', 'look_old' => 'Mid 70s, short silver hair, white jacket, round glasses.'],
            ],
        ];
    }
}
