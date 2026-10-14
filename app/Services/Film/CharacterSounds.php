<?php

namespace App\Services\Film;

use App\Models\SeriesCharacter;
use App\Support\Media;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Tiếng của nhân vật (cười, khóc, thở dài... và tiếng kêu của loài): loài đoán từ mô tả ngoại hình, cao độ riêng theo loại giọng
 * và tên nhân vật (cố định), để mỗi nhân vật cười / kêu một kiểu, giống nhau ở mọi tập. Âm gốc trong SoundLibrary::VOCALS.
 */
final class CharacterSounds
{
    /** Loài → từ khoá tiếng Anh trong ngoại hình / tên. */
    public const SPECIES = [
        'cat' => 'cat|kitten|kitty|tabby|feline',
        'dog' => 'dog|puppy|pup|corgi|poodle|terrier|retriever|beagle|shiba|husky|dachshund',
        'duck' => 'duck|duckling',
        'chicken' => 'hen|rooster|chicken',
        'bird' => 'chick|bird|sparrow|parrot|robin|canary|songbird',
        'owl' => 'owl',
        'frog' => 'frog|toad',
        'sheep' => 'sheep|lamb',
        'goat' => 'goat|kid goat',
        'cow' => 'cow|calf',
        'pig' => 'pig|piglet',
        'horse' => 'horse|pony|foal',
        'hedgehog' => 'hedgehog',
        'mouse' => 'mouse|mice|hamster|rat|squirrel|gerbil',
    ];

    /** Tiếng người gốc là trẻ nhỏ: đổi theo loại giọng của nhân vật (người lớn trầm hơn, thú / sinh vật cao hơn). */
    private const VOICE_FACTOR = [
        'kid_boy' => 1.0, 'kid_girl' => 1.06, 'adult_male' => 0.72, 'adult_female' => 0.86, 'elder' => 0.7, 'elder_female' => 0.82, 'creature' => 1.12,
    ];

    public static function species(SeriesCharacter $character): ?string
    {
        $text = mb_strtolower("{$character->name} {$character->appearance}");
        foreach (self::SPECIES as $species => $words) {
            if (preg_match('/\b('.$words.')s?\b/u', $text)) {
                return $species;
            }
        }

        return null;
    }

    /** Hệ số cao độ (cố định theo nhân vật): nhỏ bé thì cao hơn, to lớn / già thì trầm hơn, lệch nhẹ theo tên để hai nhân vật cùng loài vẫn khác. */
    public static function pitch(SeriesCharacter $character, string $group): float
    {
        $text = mb_strtolower((string) $character->appearance);
        $size = match (true) {
            (bool) preg_match('/\b(old|elderly|aged|ancient|grandpa|grandma)\b/u', $text) => 0.82,
            (bool) preg_match('/\b(tiny|little|small|baby|young)\b/u', $text) => 1.08,
            (bool) preg_match('/\b(big|large|huge|giant|tall)\b/u', $text) => 0.9,
            default => 1.0,
        };
        $spread = 1 + ((crc32(mb_strtolower($character->name)) % 7) - 3) * 0.025;
        $base = $group === 'animal' ? 1.0 : (self::VOICE_FACTOR[$character->voice['type'] ?? ''] ?? 1.0);

        return round(max(0.6, min(1.6, $base * $size * $spread)), 3);
    }

    /** Âm thanh nhân vật dùng được: tiếng người (voice_*) + tiếng kêu đúng loài. @return list<string> */
    public static function allowed(SeriesCharacter $character, array $library): array
    {
        $species = self::species($character);

        return collect($library)->filter(fn ($s) => $s['group'] === 'voice' || ($s['group'] === 'animal' && ($s['species'] ?? null) === $species))->pluck('id')->values()->all();
    }

    /** Lọc ffmpeg đổi cao độ, giữ nguyên độ dài. */
    public static function filter(float $pitch): string
    {
        if (abs($pitch - 1.0) < 0.01) {
            return 'anull';
        }

        return 'asetrate='.(int) round(44100 * $pitch).',aresample=44100,atempo='.round(1 / $pitch, 4);
    }

    /**
     * Bản nghe thử một tiếng của nhân vật (đã đổi cao độ), lưu lại để nghe lần sau không phải tạo lại.
     *
     * @throws RuntimeException
     */
    public static function preview(SeriesCharacter $character, array $sound): string
    {
        $pitch = self::pitch($character, $sound['group']);
        $path = "series/{$character->series_id}/characters/{$character->id}-sound-{$sound['id']}-".str_replace('.', '', (string) $pitch).'.wav';
        if (Media::disk()->exists($path)) {
            return $path;
        }
        $dir = sys_get_temp_dir().'/csound-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        try {
            File::put("{$dir}/in.wav", (string) Media::disk()->get($sound['files'][0]));
            $result = Process::timeout(60)->run([config('videoaff.ffmpeg'), '-v', 'error', '-y', '-nostdin', '-i', "{$dir}/in.wav", '-af', self::filter($pitch), "{$dir}/out.wav"]);
            if ($result->failed()) {
                throw new RuntimeException('Tạo tiếng nghe thử lỗi: '.mb_substr($result->errorOutput(), -200));
            }
            Media::disk()->put($path, (string) File::get("{$dir}/out.wav"));

            return $path;
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
