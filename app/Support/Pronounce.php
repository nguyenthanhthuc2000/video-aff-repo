<?php

namespace App\Support;

use App\Models\Pronunciation;

/**
 * Đổi tên riêng tiếng Anh sang cách đọc kiểu Việt trước khi đưa cho giọng Việt (Piper chỉ biết âm tiếng Việt).
 * Có sẵn tên Ngọc Rồng; người dùng thêm / sửa ở bảng "Cách đọc tên". Phụ đề và chữ trên màn hình giữ tên gốc.
 */
final class Pronounce
{
    /** Cách đọc mặc định (chữ thường => cách đọc). Cụm dài được thay trước cụm ngắn. */
    public const DEFAULTS = [
        'dragon ball' => 'Đờ ra gơn Bôn',
        'super saiyan blue' => 'Xu pơ Xai dan Bờ lu',
        'super saiyan god' => 'Xu pơ Xai dan Gót',
        'super saiyan' => 'Xu pơ Xai dan',
        'saiyans' => 'Xai dan',
        'saiyan' => 'Xai dan',
        'ultra instinct' => 'Un tra In xtinh',
        'son goku' => 'Xôn Gô cu',
        'goku black' => 'Gô cu Bờ lách',
        'goku' => 'Gô cu',
        'son gohan' => 'Xôn Gô han',
        'gohan' => 'Gô han',
        'goten' => 'Gô ten',
        'vegeta' => 'Vê ghê ta',
        'vegito' => 'Vê ghi tô',
        'gogeta' => 'Gô ghê ta',
        'future trunks' => 'Phiu chơ Trăng',
        'trunks' => 'Trăng',
        'piccolo' => 'Píc cô lô',
        'krillin' => 'Cri lin',
        'bulma' => 'Bun ma',
        'chi-chi' => 'Chi Chi',
        'master roshi' => 'Mát tơ Rô si',
        'roshi' => 'Rô si',
        'yamcha' => 'Dam cha',
        'tien shinhan' => 'Thiên Xin han',
        'tien' => 'Thiên',
        'frieza' => 'Phri dơ',
        'cell' => 'Xeo',
        'majin buu' => 'Ma din Bu',
        'kid buu' => 'Kít Bu',
        'buu' => 'Bu',
        'android' => 'An đờ roi',
        'beerus' => 'Bia rút',
        'whis' => 'Uýt',
        'broly' => 'Bờ rô li',
        'jiren' => 'Di ren',
        'shenron' => 'Sên rông',
        'kamehameha' => 'Ca mê ha mê ha',
        'spirit bomb' => 'Xpi rít Bom',
        'final flash' => 'Phai nồ Phờ lát',
        'galick gun' => 'Ga lích Gân',
        'namek' => 'Na mếch',
        'namekian' => 'Na mếch',
        'nappa' => 'Náp pa',
        'raditz' => 'Ra đít',
        'bardock' => 'Ba đốc',
        'king kai' => 'Kinh Cai',
        'videl' => 'Vi đeo',
        'zamasu' => 'Da ma xu',
        'kanzenshuu' => 'Can den xu',
        'manga' => 'man ga',
        'anime' => 'a ni mê',
        'saga' => 'xa ga',
    ];

    /** @return array<string, string> chữ thường => cách đọc (bảng của người dùng đè lên mặc định) */
    public static function table(?int $userId): array
    {
        $mine = $userId ? Pronunciation::where('user_id', $userId)->pluck('say', 'word')->mapWithKeys(fn ($say, $word) => [mb_strtolower($word) => $say])->all() : [];

        return [...self::DEFAULTS, ...$mine];
    }

    public static function apply(string $text, ?int $userId, ?array $table = null): string
    {
        $table ??= self::table($userId);
        uksort($table, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach ($table as $word => $say) {
            if ($say === '' || mb_stripos($text, $word) === false) {
                continue;
            }
            // Chỉ thay nguyên từ (không đụng chữ nằm trong từ khác), không phân biệt hoa thường.
            $text = preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'(?![\p{L}\p{N}])/iu', $say, $text) ?? $text;
        }

        return $text;
    }

    /**
     * Các từ trong đoạn văn có cách đọc (để hiện cho người dùng nghe thử / sửa).
     *
     * @return list<array{word: string, say: string, custom: bool}>
     */
    public static function found(string $text, int $userId): array
    {
        $mine = Pronunciation::where('user_id', $userId)->pluck('say', 'word')->mapWithKeys(fn ($say, $word) => [mb_strtolower($word) => $say])->all();
        $table = [...self::DEFAULTS, ...$mine];
        uksort($table, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $found = [];
        foreach ($table as $word => $say) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'(?![\p{L}\p{N}])/iu', $text)) {
                $found[] = ['word' => $word, 'say' => $say, 'custom' => isset($mine[$word])];
                // Từ ngắn nằm trong cụm dài đã tìm thấy (goku trong son goku) vẫn có thể đứng riêng nên vẫn liệt kê.
            }
        }
        $extra = collect($mine)->except(array_column($found, 'word'))->map(fn ($say, $word) => ['word' => $word, 'say' => $say, 'custom' => true])->values()->all();

        return [...$found, ...$extra];
    }
}
