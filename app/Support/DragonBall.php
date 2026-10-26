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

    /** Phong cách ảnh đánh nhau / chiêu thức: toàn thân, động, không máu, không thương tích. */
    public const ACTION_STYLE = 'Black and white manga panel, Dragon Ball Super manga style (Toyotarou, Akira Toriyama character design), clean bold ink lines, '
        .'screentone shading, no color, dynamic full-body action shot, simple background with speed lines, vertical 9:16. '
        .'Energy and motion only: no blood, no injuries, no pain. No speech bubbles, no text.';

    /** Động tác đánh nhau chung cho mọi nhân vật biết đánh: mã => [nhãn, prompt]. */
    public const ACTIONS = [
        'A1' => ['Đấm', 'throwing a powerful straight punch toward the viewer, fist forward, wind and speed lines around the fist, determined face'],
        'A2' => ['Đá', 'high flying kick in mid-air, one leg extended, motion lines, focused face'],
        'A3' => ['Đỡ đòn', 'blocking with crossed forearms in front of the face, small impact flash on the arms, feet sliding back on the ground'],
        'A4' => ['Bay lao tới', 'dashing forward through the sky, body leaning forward, trail of aura and speed lines behind'],
        'A5' => ['Gồng sức', 'charging ki, standing firm with fists clenched at the sides, strong glowing aura rising, small rocks floating'],
        'A6' => ['Bị đánh bật', 'knocked back in mid-air by a bright impact flash, arms flung out, eyes squeezed shut, shockwave rings, no injuries'],
    ];

    /** Nhân vật không đánh nhau: không có ảnh động tác. */
    public const NON_FIGHTERS = ['bulma', 'chichi', 'whis', 'shenron', 'dragon-balls'];

    /** Chiêu thức riêng: key nhân vật => [mã => [nhãn, prompt]]. */
    public const SKILLS = [
        'goku' => [
            'S1' => ['Kamehameha', 'performing the Kamehameha: cupped hands thrust forward releasing a huge glowing energy wave beam, shockwave around'],
            'S2' => ['Genki Dama', 'raising both arms to the sky holding a gigantic glowing Spirit Bomb energy sphere above his head'],
            'S3' => ['Kaio-ken', 'using Kaio-ken: intense flaming aura bursting around the body, clenched fists, powerful stance'],
            'S4' => ['Dịch chuyển tức thời', 'Instant Transmission: two fingers on his forehead, body fading with afterimage lines'],
        ],
        'goku-ssj' => ['S1' => ['Kamehameha', 'firing a massive Kamehameha energy wave from cupped hands, golden-haired, wind blasting around']],
        'goku-ui' => ['S1' => ['Né đòn bản năng', 'effortlessly dodging with calm eyes, several afterimages around him, silver flowing aura']],
        'vegeta' => [
            'S1' => ['Galick Gun', 'firing the Galick Gun: both palms forward side by side, huge purple-white energy beam, shockwave'],
            'S2' => ['Final Flash', 'Final Flash: arms spread wide then palms forward releasing a gigantic blinding energy beam'],
            'S3' => ['Big Bang Attack', 'Big Bang Attack: one arm extended, open palm, a large glowing energy sphere forming in front of it'],
        ],
        'vegeta-blue' => ['S1' => ['Final Flash', 'Final Flash with blue aura: palms forward releasing a gigantic blinding energy beam']],
        'gohan' => [
            'S1' => ['Masenko', 'Masenko: both palms together above the forehead firing a bright energy blast'],
            'S2' => ['Kamehameha cha con', 'Father-Son Kamehameha: one arm thrust forward firing a giant energy wave, a faint spirit of his father behind him'],
        ],
        'kid-gohan' => ['S1' => ['Masenko', 'young boy firing Masenko: palms together above the forehead, bright energy blast']],
        'piccolo' => ['S1' => ['Makankosappo', 'Special Beam Cannon: two fingers on the forehead, spiralling energy beam drill shooting forward']],
        'krillin' => ['S1' => ['Kienzan', 'Destructo Disc: one arm raised holding a spinning flat glowing energy disc above his head']],
        'tien' => [
            'S1' => ['Tri-Beam', 'Tri-Beam: hands forming a triangle in front of him, intense energy blast through the triangle'],
            'S2' => ['Thái Dương Hạ San', 'Solar Flare: hands spread beside the face, blinding white flash of light'],
        ],
        'yamcha' => ['S1' => ['Lang Nha Phong Phong Quyền', 'Wolf Fang Fist: rushing forward with clawed hands, a wolf-shaped aura behind him, speed lines']],
        'roshi' => ['S1' => ['Kamehameha MAX', 'old master at full power, muscular, firing a huge Kamehameha energy wave from cupped hands']],
        'future-trunks' => [
            'S1' => ['Burning Attack', 'Burning Attack: rapid hand signs then palms forward releasing a fiery energy blast'],
            'S2' => ['Chém kiếm', 'sword slash: sword swung in a wide glowing arc, motion lines'],
        ],
        'goten-trunks' => ['S1' => ['Điệu nhảy hợp thể', 'two kids doing the Fusion Dance pose side by side, arms stretched sideways, fingers touching, glowing light']],
        'frieza' => [
            'S1' => ['Tia tử thần', 'Death Beam: one finger pointed forward firing a thin piercing energy beam, cold smile'],
            'S2' => ['Quả cầu tử thần', 'Death Ball: one finger raised holding a gigantic glowing energy sphere above him'],
        ],
        'cell' => ['S1' => ['Kamehameha hoàn hảo', 'Perfect Kamehameha: cupped hands forward firing a huge energy wave, wings spread']],
        'majin-buu' => ['S1' => ['Tia biến kẹo', 'Candy Beam: head antenna glowing, firing a playful wavy beam with sparkles']],
        'kid-buu' => ['S1' => ['Cầu năng lượng', 'raising a giant glowing energy sphere above his head, wild grin']],
        'android-18' => ['S1' => ['Bắn chưởng', 'firing a quick energy blast from one open palm, calm cool face']],
        'android-17' => ['S1' => ['Lá chắn năng lượng', 'standing inside a glowing spherical energy barrier, arms crossed in front']],
        'beerus' => ['S1' => ['Hakai', 'Hakai: one hand raised, palm open, a glowing sphere of destruction energy, calm eyes']],
        'broly' => ['S1' => ['Eraser Cannon', 'Eraser Cannon: one arm thrust forward with a large glowing green energy sphere, wild aura']],
        'jiren' => ['S1' => ['Power Impact', 'Power Impact: one fist forward sending a giant shockwave of energy, arms crossed aura']],
        'goku-black' => ['S1' => ['Lưỡi hái năng lượng', 'a long glowing energy blade extending from his hand, slashing arc']],
        'vegito' => [
            'S1' => ['Kiếm năng lượng', 'Spirit Sword: a long glowing energy blade extending from his hand'],
            'S2' => ['Final Kamehameha', 'Final Kamehameha: cupped hands thrust forward, giant energy wave beam'],
        ],
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

    /** Các ô ảnh của một nhân vật theo tab: faces = biểu cảm, fight = động tác chung + chiêu thức riêng. @return array<string, array{0: string, 1: string}> */
    public static function poses(string $character, string $tab = 'faces'): array
    {
        if ($tab === 'faces') {
            return self::EXPRESSIONS;
        }

        return in_array($character, self::NON_FIGHTERS, true) ? [] : self::ACTIONS + (self::SKILLS[$character] ?? []);
    }

    /** Ô gợi ý vẽ khi bấm "Vẽ còn thiếu". @return list<string> */
    public static function suggested(string $character, string $tab = 'faces'): array
    {
        return $tab === 'faces' ? self::CHARACTERS[$character][2] : array_keys(self::poses($character, 'fight'));
    }

    /** Nhãn và prompt của một ô (biểu cảm, động tác hoặc chiêu). @return array{0: string, 1: string}|null */
    public static function pose(string $character, string $code): ?array
    {
        return self::poses($character, self::tabOf($code))[$code] ?? null;
    }

    public static function tabOf(string $code): string
    {
        return str_starts_with($code, 'E') ? 'faces' : 'fight';
    }

    /** Prompt vẽ một nhân vật với một biểu cảm / động tác / chiêu; safe: bản bỏ tên khi AI đã từ chối; poseText: phần biểu cảm / động tác đã viết lại. */
    public static function prompt(string $character, string $expression, bool $withReference = false, bool $safe = false, ?string $poseText = null): string
    {
        [, $look] = self::CHARACTERS[$character];
        if ($safe) {
            $look = self::scrub($look);
        }
        $action = self::tabOf($expression) === 'fight';
        $style = $action ? self::ACTION_STYLE : ($safe ? self::SAFE_STYLE : self::STYLE);
        $pose = $poseText ?? (self::pose($character, $expression)[1] ?? '');
        $lines = [$style, "{$look}.", ($action ? 'Action: ' : 'Expression: ').$pose.'.'];
        if ($withReference) {
            $lines[] = $action
                ? 'The attached image is this same character: keep the face, hair and outfit identical, draw them in the action above.'
                : 'The attached image is this same character: keep the face, hair and outfit identical, change only the expression.';
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

    /** Khoá các nhân vật được nhắc trong đoạn chữ (chủ đề, câu tư liệu), so tên không phân biệt hoa thường. @return list<string> */
    public static function charactersIn(string $text): array
    {
        $haystack = mb_strtolower($text);
        $keys = [];
        foreach (self::CHARACTERS as $key => [$label]) {
            $short = mb_strtolower((string) preg_replace('/^Son /', '', $label));
            if (mb_strlen($short) >= 4 && preg_match('/(^|[^\p{L}])'.preg_quote($short, '/').'($|[^\p{L}])/u', $haystack)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
