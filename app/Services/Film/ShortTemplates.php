<?php

namespace App\Services\Film;

use App\Models\VirtualBand;
use Illuminate\Support\Str;

/**
 * Mẫu Shorts nhạc (theo các dạng hiệu quả của kênh tham khảo), luôn với ban nhạc / ca sĩ ẢO: 20–30 giây, khung dọc 9:16, hook trong 2 giây đầu,
 * tên video và chữ trên hình theo mẫu, mô tả có dòng "AI-generated". Mỗi biến thể (năm, cặp đấu, thành viên, địa điểm) thành một MV.
 */
final class ShortTemplates
{
    public const TEMPLATES = [
        'lineup' => [
            'label' => 'Classic Lineup (năm)',
            'hint' => 'Cả đội hình ban nhạc thời đỉnh cao, giới thiệu từng thành viên. Biến thể: năm (mỗi dòng một năm).',
            'variation' => 'Năm, ví dụ 1977',
            'lip_sync' => 'chorus',
        ],
        'versus' => [
            'label' => 'A VS B',
            'hint' => 'Hai ca sĩ (hoặc hai phiên bản) thay nhau hát cùng điệp khúc, người xem tranh luận ai hay hơn. Biến thể: "A | B" mỗi dòng.',
            'variation' => 'Tên A | Tên B',
            'lip_sync' => 'full',
        ],
        'ages' => [
            'label' => 'Trẻ VS Già',
            'hint' => 'Cùng một thành viên lúc trẻ (thời kỳ của ban) và hiện tại xen kẽ nhau. Biến thể: tên thành viên.',
            'variation' => 'Tên thành viên',
            'lip_sync' => 'chorus',
        ],
        'journey' => [
            'label' => 'Hành trình qua các năm',
            'hint' => 'Một thành viên / cả ban qua từng thời kỳ, già dần theo năm, kết thúc cảm động. Biến thể: tên thành viên hoặc tên ban.',
            'variation' => 'Tên thành viên hoặc tên ban',
            'lip_sync' => 'none',
        ],
        'fanwalk' => [
            'label' => 'Fan đi phố',
            'hint' => 'Một fan mặc áo của ban nhạc ảo đi giữa phố đông, ai cũng ngoái nhìn, chuyển động chậm theo nhạc. Biến thể: địa điểm.',
            'variation' => 'Địa điểm, ví dụ quảng trường mưa đêm',
            'lip_sync' => 'none',
        ],
    ];

    /** Năm hiện tại cho các mẫu "bây giờ". */
    public static function now(): int
    {
        return (int) now()->year;
    }

    /** Biến thể gợi ý sẵn cho ban nhạc. @return list<string> */
    public static function defaultVariations(string $template, VirtualBand $band): array
    {
        $members = array_column($band->members ?? [], 'name');
        $start = self::startYear($band);

        return match ($template) {
            'lineup' => [(string) $start, (string) ($start + 4), (string) ($start + 9)],
            'versus' => count($members) >= 2 ? ["{$members[0]} | {$members[1]}", "{$band->name} {$start} | {$band->name} ".self::now()] : ["{$band->name} {$start} | {$band->name} ".self::now()],
            'ages', 'journey' => array_slice($members ?: [$band->name], 0, 3),
            default => ['quảng trường đông người lúc hoàng hôn', 'con phố mưa đêm đầy đèn neon', 'ga tàu điện ngầm giờ cao điểm'],
        };
    }

    public static function startYear(VirtualBand $band): int
    {
        return preg_match('/(19|20)\d{2}/', $band->era, $m) ? (int) $m[0] : 1975;
    }

    /** @return array{title: string, idea: string, brief: string, hashtags: list<string>, description: string, lip_sync: string, members: list<array>} */
    public static function build(string $template, VirtualBand $band, string $variation, string $songTitle): array
    {
        $variation = trim($variation);
        $members = $band->members ?? [];
        $find = fn (string $name) => collect($members)->first(fn ($m) => mb_strtolower(trim($m['name'])) === mb_strtolower(trim($name)));
        $start = self::startYear($band);
        $parts = array_map('trim', explode('|', $variation));
        [$a, $b] = [$parts[0] ?? '', $parts[1] ?? ''];
        $member = $find($variation);

        [$title, $concept, $cast, $plan] = match ($template) {
            'lineup' => [
                "{$band->name} — Classic Lineup ({$variation})",
                "The fictional band {$band->name} in {$variation}, at the peak of their career: the full classic lineup.",
                $members,
                "0–2s HOOK: the whole band in a dramatic group shot on stage, smoke and backlight (label: \"{$band->name} · {$variation}\").\n"
                    ."Then one short shot per member (2–4 s each), playing their instrument, in {$variation} clothes and hair (label: \"Name — role\").\n"
                    .'Final chorus: the whole band performing together, the lead singer sings to the camera (lip sync), big ending pose.',
            ],
            'versus' => [
                "{$a} VS {$b} — ".Str::limit($songTitle, 40, ''),
                "Battle: {$a} versus {$b} sing the same chorus of \"{$songTitle}\". Who sings it better?",
                array_values(array_filter([$find($a), $find($b)])),
                "0–2s HOOK: split-feel shot, {$a} on one side and {$b} on the other, staring at each other (label: \"{$a} VS {$b}\").\n"
                    ."Then alternate: {$a} sings a line (label \"{$a}\"), {$b} sings the next line (label \"{$b}\"), each 2–4 s, same stage, contrasting looks and light.\n"
                    .'Ending: both together on stage singing the last line, then a freeze.',
            ],
            'ages' => [
                ($member['name'] ?? $variation).' — Young VS Old ('.$start.' vs '.self::now().')',
                ($member['name'] ?? $variation)." of {$band->name}: young in {$start} versus today in ".self::now().', same song, same spirit.',
                $member ? [
                    [...$member, 'name' => "{$member['name']} ({$start})"],
                    [...$member, 'name' => "{$member['name']} (".self::now().')', 'look' => $member['look_old'] ?? $member['look'], 'image_path' => null],
                ] : [],
                "0–2s HOOK: the young and the old version face to face, as if in a mirror (label: \"{$start} VS ".self::now()."\").\n"
                    ."Then alternate young ({$start}, label \"{$start}\") and old (".self::now().', label "'.self::now()."\") performing the same moves, 2–3 s each.\n"
                    .'Ending: both side by side, the old one smiles at the young one.',
            ],
            'journey' => [
                ($member['name'] ?? $band->name).": Through the Years ({$start}–".self::now().')',
                'A journey through the years of '.($member['name'] ?? $band->name)." ({$band->name}), from the first gig in {$start} to today.",
                $member ? [$member] : $members,
                '0–2s HOOK: a close-up today ('.self::now().") looking at an old photo (label: \"{$start} – ".self::now()."\").\n"
                    ."Then 5–7 shots moving through the years, each a different era and aging slightly (label: the year, from {$start} to ".self::now().").\n"
                    .'Ending: today, on an empty stage, raising a hand to the crowd; warm, emotional light.',
            ],
            default => [
                "Badass {$band->name} Fan Walking Like a Boss! 🤘",
                "A tattooed fan wearing a {$band->name} band T-shirt walks through {$variation}; everyone turns their head.",
                [],
                "0–2s HOOK: low-angle slow-motion shot of the fan stepping forward, {$band->name} T-shirt clearly visible (label: \"{$band->name} fan\").\n"
                    ."Then 5–7 shots: walking through {$variation}, people turning heads, a kid copying the walk, a guard nodding, all in slow motion on the beat.\n"
                    .'Ending: the fan throws the horns 🤘 to the camera.',
            ],
        };

        $roster = collect($cast)->map(fn ($m) => "- {$m['name']} ({$m['role']}): {$m['look']}")->implode("\n");
        $idea = 'Short-form music video (vertical 9:16, about 25 seconds) for YouTube Shorts / TikTok, format: "'.self::TEMPLATES[$template]['label']."\".\n"
            ."{$concept}\n"
            ."Band: {$band->name}, a FICTIONAL {$band->genre} band ({$band->era}). ".($band->lore ? "Lore: {$band->lore}\n" : "\n")
            .($roster !== '' ? "Keep exactly these characters (names and looks), no other band members:\n{$roster}\n" : '')
            .'All people are fictional; never depict or name real artists.';
        $brief = "Shot plan (follow it; all on-screen text goes in \"label\", short, max 40 characters):\n{$plan}\n"
            .'Every shot must be readable at a glance on a phone; strong contrast, one clear subject.';
        $decade = (int) floor(($template === 'lineup' && ctype_digit($variation) ? (int) $variation : $start) / 10) * 10;
        $tag = fn (string $text) => '#'.preg_replace('/[^\p{L}\p{N}]+/u', '', Str::title(Str::ascii($text)));
        $hashtags = array_values(array_unique(array_filter([
            '#shorts', $tag($band->name), $tag($band->genre), '#'.substr((string) $decade, 2).'s'.preg_replace('/[^a-z]/', '', mb_strtolower(explode(' ', $band->genre)[0] ?? 'music')),
            '#aimusicvideo', '#originalmusic', '#virtualband',
        ], fn ($t) => mb_strlen($t) > 2)));

        return [
            'title' => Str::limit($title, 95, ''),
            'idea' => $idea,
            'brief' => $brief,
            'hashtags' => $hashtags,
            'description' => "⚠️ This is an AI-generated video for entertainment. {$band->name} is a fictional band and all people shown are AI characters.\n"
                ."Original song: \"{$songTitle}\".\n\n".implode(' ', $hashtags),
            'lip_sync' => self::TEMPLATES[$template]['lip_sync'],
            'members' => array_values($cast),
        ];
    }
}
