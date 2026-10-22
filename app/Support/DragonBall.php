<?php

namespace App\Support;

/** Thư viện nhân vật 7 Viên Ngọc Rồng: mô tả ngoại hình, biểu cảm, phong cách manga trắng đen cho Muse. */
final class DragonBall
{
    /** Phong cách chung (giống trang manga Dragon Ball Super): đặt đầu mọi prompt vẽ chân dung. */
    public const STYLE = 'Black and white manga panel, Dragon Ball Super manga style (Toyotarou, Akira Toriyama character design), clean bold ink lines, '
        .'screentone shading, no color, close-up bust shot, plain white background, vertical 9:16. No speech bubbles, no text.';

    /** Mã biểu cảm => [nhãn tiếng Việt, prompt tiếng Anh]. */
    public const EXPRESSIONS = [
        'E1' => ['Bình thường', 'calm relaxed face, gentle eyes, mouth closed'],
        'E2' => ['Vui vẻ', 'warm cheerful smile, bright eyes'],
        'E3' => ['Nghiêm nghị', 'stern serious look, slightly furrowed brows, mouth closed'],
        'E4' => ['Ngạc nhiên', 'surprised, eyes wide, small open mouth'],
        'E5' => ['Quyết tâm', 'confident determined look, slight smile'],
        'E6' => ['Trầm tư', 'thoughtful, looking down softly'],
        'E7' => ['Tự tin', 'confident smirk, relaxed eyes'],
        'E8' => ['Tập trung', 'focused, fists raised in a ready stance, gentle glowing aura'],
        'E9' => ['Thắc mắc', 'puzzled, head tilted, one eyebrow raised'],
    ];

    /** key => [tên, mô tả ngoại hình (tiếng Anh), biểu cảm gợi ý]. */
    public const CHARACTERS = [
        'goku' => ['Son Goku', 'Son Goku, spiky black hair with bangs, gi with Kanji on chest, wristbands, muscular', ['E1', 'E2', 'E3', 'E4', 'E5', 'E6', 'E7', 'E8', 'E9']],
        'goku-ssj' => ['Goku Super Saiyan', 'Son Goku Super Saiyan, tall spiky hair left blank white (blonde in black-and-white manga), sharp eyes, intense aura lines', ['E1', 'E3', 'E5', 'E8']],
        'goku-ui' => ['Goku Ultra Instinct', 'Son Goku Ultra Instinct, silver hair in light grey screentone, calm empty eyes, flowing aura', ['E1', 'E5']],
        'vegeta' => ['Vegeta', "Vegeta, tall flame-shaped black hair with widow's peak, Saiyan battle armor with shoulder pads, gloves", ['E1', 'E3', 'E4', 'E5', 'E7', 'E8']],
        'vegeta-blue' => ['Vegeta Super Saiyan Blue', 'Vegeta Super Saiyan Blue, upright spiky hair with light screentone, serious glare', ['E1', 'E3', 'E5', 'E8']],
        'gohan' => ['Son Gohan', 'Son Gohan adult, short spiky black hair, gi in screentone', ['E1', 'E2', 'E3', 'E5', 'E8']],
        'kid-gohan' => ['Gohan bé (Namek)', 'young Son Gohan, kid with bowl-like spiky hair, Saiyan armor', ['E1', 'E4', 'E6', 'E8']],
        'piccolo' => ['Piccolo', 'Piccolo, Namekian with antennae and pointed ears (screentone skin), white turban and cape', ['E1', 'E5', 'E7']],
        'krillin' => ['Krillin', 'Krillin, bald monk with six dots on forehead, no nose, gi with Turtle School symbol', ['E1', 'E2', 'E4', 'E6', 'E9']],
        'bulma' => ['Bulma', 'Bulma, short bob hair, Capsule Corp outfit', ['E1', 'E2', 'E3', 'E9']],
        'chichi' => ['Chi-Chi', 'Chi-Chi, black hair in a bun, Chinese-style dress', ['E1', 'E3', 'E6']],
        'future-trunks' => ['Future Trunks', 'Future Trunks, long straight hair, Capsule Corp jacket, sword on back', ['E1', 'E5', 'E8']],
        'goten-trunks' => ['Goten và Trunks nhí', 'kid Goten (Goku-like spiky hair) and kid Trunks (bowl cut) side by side', ['E1', 'E2', 'E9']],
        'roshi' => ['Master Roshi', 'Master Roshi, old bald man with long white beard, sunglasses, turtle shell on back', ['E1', 'E2', 'E7', 'E9']],
        'yamcha' => ['Yamcha', 'Yamcha, scars on face, spiky hair, gi', ['E1', 'E4', 'E6', 'E9']],
        'tien' => ['Tien Shinhan', 'Tien Shinhan, bald, third eye on forehead, muscular', ['E1', 'E5']],
        'frieza' => ['Frieza', 'Frieza final form, smooth white body and head with dome patches in screentone, tail, dark eyes', ['E1', 'E3', 'E4', 'E7']],
        'cell' => ['Cell', 'Perfect Cell, insect-like armor with spots in screentone, crown-shaped head, wings', ['E1', 'E3', 'E7']],
        'majin-buu' => ['Majin Buu', 'Fat Majin Buu, round body in light screentone, head antenna, cape, belt with M', ['E1', 'E2', 'E3', 'E9']],
        'kid-buu' => ['Kid Buu', 'Kid Buu, small and thin, long head antenna, wild grin', ['E1', 'E7', 'E8']],
        'android-18' => ['Android 18', 'Android 18, short straight bob hair, vest and striped sleeves', ['E1', 'E7']],
        'android-17' => ['Android 17', 'Android 17, black shoulder-length hair, scarf, ranger outfit', ['E1', 'E5']],
        'beerus' => ['Beerus', 'Beerus, Egyptian cat-like god of destruction, large ears, gold jewelry, arms crossed', ['E1', 'E3', 'E7', 'E9']],
        'whis' => ['Whis', 'Whis, tall angel with white mohawk hair, halo ring around neck, staff', ['E1', 'E2']],
        'broly' => ['Broly', 'Broly, huge and muscular, wild long hair, scar, fur belt', ['E1', 'E3', 'E8']],
        'jiren' => ['Jiren', 'Jiren, bald grey alien, big dark eyes, Pride Troopers suit', ['E1', 'E5']],
        'goku-black' => ['Goku Black', "Goku Black, Goku's face with cold eyes, dark gi, green Potara earring", ['E1', 'E3', 'E7']],
        'vegito' => ['Vegito', 'Vegito, fused warrior with Potara earrings, spiky hair with one bang, vest over gi', ['E1', 'E5', 'E7']],
        'shenron' => ['Shenron', 'Shenron, giant eternal dragon coiling in the sky, glowing eyes', ['E1']],
        'dragon-balls' => ['7 viên ngọc rồng', 'the seven Dragon Balls with stars inside, glowing, arranged in a circle', ['E1']],
    ];

    /** Phong cách khi AI từ chối tên phim / tác giả: chỉ tả nét vẽ. */
    public const SAFE_STYLE = 'Black and white Japanese shonen manga panel in classic 1990s martial-arts manga style, clean bold ink lines, '
        .'screentone shading, no color, close-up bust shot, plain white background, vertical 9:16. Original character design. No speech bubbles, no text.';

    /** Từ dễ bị AI vẽ ảnh từ chối (tên phim, tác giả, thuật ngữ riêng) => chữ mô tả thay thế. */
    private const SCRUB_TERMS = [
        'Super Saiyan Blue' => 'blue-aura powered-up', 'Super Saiyan' => 'golden-haired powered-up', 'Ultra Instinct' => 'silver-haired calm',
        'Saiyan' => 'warrior', 'Namekian' => 'green-skinned alien', 'Namek' => 'green alien planet',
        'Dragon Ball Super' => '', 'Dragon Ball Z' => '', 'Dragon Balls' => 'magic orbs', 'Dragon Ball' => '',
        'Akira Toriyama' => '', 'Toriyama' => '', 'Toyotarou' => '', 'Toei' => '',
        'Potara' => '', 'Capsule Corp' => 'tech company', 'Pride Troopers' => 'hero squad', 'Kanji' => 'symbol', 'Turtle School' => 'school',
        'Majin' => '', 'Kamehameha' => 'energy wave', 'Shenron' => 'giant eternal dragon',
    ];

    /** Bỏ tên nhân vật, tên phim, tác giả khỏi prompt (giữ mô tả ngoại hình) để thử lại khi AI từ chối. */
    public static function scrub(string $text): string
    {
        $names = [];
        foreach (self::CHARACTERS as [$label]) {
            $names[] = $label;
            $names[] = preg_replace('/^Son /', '', $label);
        }
        foreach (['Goku', 'Gohan', 'Goten', 'Trunks', 'Vegeta', 'Vegito', 'Gogeta', 'Frieza', 'Freeza', 'Krillin', 'Piccolo', 'Bulma', 'Chi-Chi', 'Roshi', 'Yamcha', 'Tien', 'Cell', 'Buu', 'Beerus', 'Whis', 'Broly', 'Jiren', 'Zamasu', 'Raditz', 'Nappa'] as $name) {
            $names[] = $name;
        }
        usort($names, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $terms = self::SCRUB_TERMS;
        uksort($terms, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        foreach ($terms as $term => $replacement) {
            $text = preg_replace('/\b'.preg_quote($term, '/').'\b/iu', $replacement, $text);
        }
        foreach (array_unique($names) as $name) {
            $text = preg_replace('/\b'.preg_quote($name, '/').'\b/iu', 'the character', $text);
        }
        $text = preg_replace(['/\(\s*[,;]?\s*\)/u', '/ +([,.;:])/u', '/([,;:])\s*[,;.]+/u', '/ {2,}/u'], ['', '$1', '$1', ' '], $text);

        return trim((string) $text);
    }

    /** Prompt vẽ chân dung một nhân vật với một biểu cảm; safe: bản bỏ tên khi AI đã từ chối. */
    public static function prompt(string $character, string $expression, bool $withReference = false, bool $safe = false): string
    {
        [, $look] = self::CHARACTERS[$character];
        if ($safe) {
            $look = self::scrub($look);
        }
        $lines = [$safe ? self::SAFE_STYLE : self::STYLE, "{$look}.", 'Expression: '.self::EXPRESSIONS[$expression][1].'.'];
        if ($withReference) {
            $lines[] = 'The attached image is this same character: keep the face, hair and outfit identical, change only the expression.';
        }

        return implode("\n", $lines);
    }

    /** Khoá nhân vật theo tên (không phân biệt hoa thường; khớp cả khi tên ghi thiếu "Son"). */
    public static function keyFor(string $name): ?string
    {
        $needle = mb_strtolower(trim($name));
        foreach (self::CHARACTERS as $key => [$label]) {
            if (mb_strtolower($label) === $needle || mb_strtolower(preg_replace('/^Son /', '', $label)) === $needle) {
                return $key;
            }
        }

        return null;
    }
}
