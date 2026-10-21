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
        'E1' => ['Bình thường', 'neutral calm face, mouth closed'],
        'E2' => ['Cười hào hứng', 'big grin, excited eyes, slight blush lines'],
        'E3' => ['Giận dữ', 'furious, clenched teeth, veins on forehead, furrowed brows'],
        'E4' => ['Sốc', 'shocked, eyes wide with tiny pupils, mouth open, sweat drops, exclamation mark above head'],
        'E5' => ['Quyết tâm', 'serious determined glare, small smirk, wind in hair'],
        'E6' => ['Buồn', 'sad, teary eyes, head lowered'],
        'E7' => ['Cười khinh', 'smug arrogant smirk, half-closed eyes'],
        'E8' => ['Hét gồng sức', 'screaming power-up, mouth wide open, speed lines, cracks and debris'],
        'E9' => ['Ngơ ngác / hài', 'confused comedic face, dot eyes, sweat drop, question mark above head'],
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

    /** Prompt vẽ chân dung một nhân vật với một biểu cảm. */
    public static function prompt(string $character, string $expression, bool $withReference = false): string
    {
        [, $look] = self::CHARACTERS[$character];
        $lines = [self::STYLE, "{$look}.", 'Expression: '.self::EXPRESSIONS[$expression][1].'.'];
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
