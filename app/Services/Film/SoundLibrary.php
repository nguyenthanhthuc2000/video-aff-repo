<?php

namespace App\Services\Film;

use App\Support\Media;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use ZipArchive;

/**
 * Kho âm thanh hiệu ứng miễn phí cho phim (dùng thương mại được): gói CC0 của Kenney (kenney.nl) + hiệu ứng hoạt hình tự tạo
 * bằng ffmpeg (của mình) + tiếng nhân vật (cười, khóc, thở dài, hắt hơi... và tiếng kêu của thú) thu thật từ BigSoundBank (CC0).
 * Mỗi mục có id, mô tả tiếng Anh (để Claude chọn), nhóm, các biến thể và thời lượng. Tiếng nhân vật được đổi cao độ theo từng
 * nhân vật khi ghép (CharacterSounds), nên mỗi nhân vật cười / kêu một kiểu riêng.
 */
final class SoundLibrary
{
    public const ROOT = 'sounds/library';

    public const CATALOG = self::ROOT.'/catalog.json';

    /** Gói Kenney (CC0). */
    public const PACKS = [
        'interface' => 'https://kenney.nl/media/pages/assets/interface-sounds/fa43c1dd4d-1677589452/kenney_interface-sounds.zip',
        'impact' => 'https://kenney.nl/media/pages/assets/impact-sounds/87b4ddecda-1677589768/kenney_impact-sounds.zip',
        'jingles' => 'https://kenney.nl/media/pages/assets/music-jingles/f37e530b9e-1677590399/kenney_music-jingles.zip',
        'ui' => 'https://kenney.nl/media/pages/assets/ui-audio/490d233f68-1677590494/kenney_ui-audio.zip',
        'rpg' => 'https://kenney.nl/media/pages/assets/rpg-audio/8e99002d76-1677590336/kenney_rpg-audio.zip',
    ];

    /** Bỏ âm thanh không hợp phim trẻ em (dao, đấm) và file xem trước. */
    public const EXCLUDE = '/knife|punch|preview|mining/i';

    /** Mô tả theo nhóm (tên file bỏ số thứ tự). Nhóm không có ở đây thì tự sinh mô tả từ tên. */
    public const DESCRIPTIONS = [
        'footstep_carpet' => ['foley', 'soft footsteps on carpet'],
        'footstep_concrete' => ['foley', 'footsteps on stone or pavement'],
        'footstep_grass' => ['foley', 'footsteps on grass'],
        'footstep_snow' => ['foley', 'crunchy footsteps in snow'],
        'footstep_wood' => ['foley', 'footsteps on wooden planks or a dock'],
        'footstep' => ['foley', 'single footstep on a wooden floor'],
        'impactBell_heavy' => ['impact', 'big bell clang'],
        'impactGeneric_light' => ['impact', 'light tap or bump'],
        'impactGlass_heavy' => ['impact', 'heavy glass clink'],
        'impactGlass_light' => ['impact', 'light glass clink, like a jar'],
        'impactGlass_medium' => ['impact', 'glass bottle clink'],
        'impactMetal_heavy' => ['impact', 'heavy metal clang'],
        'impactMetal_light' => ['impact', 'small metal ting'],
        'impactMetal_medium' => ['impact', 'metal bucket or pole bang'],
        'impactPlank_medium' => ['impact', 'wooden plank knock'],
        'impactPlate_heavy' => ['impact', 'heavy ceramic plate clatter'],
        'impactPlate_light' => ['impact', 'light ceramic plate tap'],
        'impactPlate_medium' => ['impact', 'plate set down on a table'],
        'impactSoft_heavy' => ['impact', 'soft heavy thud, like falling on a cushion (cartoon bonk)'],
        'impactSoft_medium' => ['impact', 'soft thud, like a bag dropped'],
        'impactTin_medium' => ['impact', 'tin can clank'],
        'impactWood_heavy' => ['impact', 'heavy wooden thump, like a crate dropped'],
        'impactWood_light' => ['impact', 'light wooden knock'],
        'impactWood_medium' => ['impact', 'wooden knock, like knocking on a door'],
        'back' => ['ui', 'soft backwards blip'],
        'bong' => ['ui', 'soft bong'],
        'click' => ['ui', 'small click'],
        'close' => ['ui', 'soft close blip'],
        'confirmation' => ['ui', 'positive confirmation chime (a good idea / correct answer)'],
        'drop' => ['ui', 'drop plop'],
        'error' => ['ui', 'wrong-answer buzz (mistake, oops)'],
        'glass' => ['ui', 'glassy ping'],
        'glitch' => ['ui', 'short digital glitch'],
        'maximize' => ['ui', 'quick rising blip (pop up, appear)'],
        'minimize' => ['ui', 'quick falling blip (shrink, disappear)'],
        'open' => ['ui', 'open blip'],
        'pluck' => ['ui', 'string pluck'],
        'question' => ['ui', 'curious question blip (hmm?)'],
        'scratch' => ['ui', 'quick scratch'],
        'scroll' => ['ui', 'paper scroll or flick'],
        'select' => ['ui', 'select blip'],
        'switch' => ['ui', 'switch click'],
        'tick' => ['ui', 'clock tick'],
        'toggle' => ['ui', 'toggle click'],
        'mouseclick' => ['ui', 'mouse click'],
        'mouserelease' => ['ui', 'mouse release'],
        'rollover' => ['ui', 'soft rollover blip'],
        'beltHandle' => ['foley', 'leather belt rustle'],
        'bookClose' => ['foley', 'book closing'],
        'bookFlip' => ['foley', 'flipping book pages'],
        'bookOpen' => ['foley', 'book opening'],
        'bookPlace' => ['foley', 'book placed on a table'],
        'chop' => ['foley', 'chopping wood'],
        'cloth' => ['foley', 'cloth rustle (moving, waving a flag)'],
        'clothBelt' => ['foley', 'clothes and belt rustle'],
        'creak' => ['foley', 'wooden creak (old door, ship, dock)'],
        'doorClose' => ['foley', 'door closing'],
        'doorOpen' => ['foley', 'door opening'],
        'dropLeather' => ['foley', 'leather bag dropped'],
        'handleCoins' => ['foley', 'jingling coins'],
        'handleSmallLeather' => ['foley', 'small leather pouch handled'],
        'metalClick' => ['foley', 'metal click'],
        'metalLatch' => ['foley', 'metal latch'],
        'metalPot' => ['foley', 'metal pot clang (kitchen)'],
        'jingles_HIT' => ['jingle', 'short orchestral hit jingle (dramatic moment, reveal)'],
        'jingles_NES' => ['jingle', 'short 8-bit game jingle (playful, success or fail)'],
        'jingles_PIZZI' => ['jingle', 'short pizzicato strings jingle (playful, sneaky, curious)'],
        'jingles_SAX' => ['jingle', 'short saxophone jingle (cool, funny)'],
        'jingles_STEEL' => ['jingle', 'short steel-drum jingle (happy, sunny, celebration)'],
    ];

    /**
     * Hiệu ứng hoạt hình tự tạo bằng ffmpeg: [nhóm, mô tả, thời lượng giây, biểu thức aevalsrc hoặc chuỗi lọc noise].
     *
     * @var array<string, array{0: string, 1: string, 2: float, 3: string}>
     */
    public const CARTOON = [
        'whoosh_short' => ['transition', 'quick cartoon whoosh (fast move, scene transition)', 0.6, 'noise:0.6:900:0.15:0.45'],
        'whoosh_long' => ['transition', 'long airy whoosh (scene transition, flying by)', 1.2, 'noise:1.2:700:0.4:0.8'],
        'swish' => ['transition', 'tiny swish (quick turn, wave of a hand)', 0.35, 'noise:0.35:2200:0.08:0.27'],
        'boing' => ['cartoon', 'cartoon boing (bounce, jump, surprise)', 0.7, '0.6*sin(2*PI*(180+90*sin(2*PI*14*t)*exp(-3*t))*t)*exp(-4*t)'],
        'pop' => ['cartoon', 'bubbly pop (something appears)', 0.15, '0.8*sin(2*PI*(700-3000*t)*t)*exp(-25*t)'],
        'slide_up' => ['cartoon', 'slide whistle going up (rising, idea, getting excited)', 0.7, '0.4*sin(2*PI*(400*t+900*t*t))*min(1,8*t)*min(1,(0.7-t)*10)'],
        'slide_down' => ['cartoon', 'slide whistle going down (falling, disappointment)', 0.8, '0.4*sin(2*PI*(1300*t-700*t*t))*min(1,8*t)*min(1,(0.8-t)*10)'],
        'ding' => ['cartoon', 'bright ding (idea, discovery, aha!)', 1.2, '(0.5*sin(2*PI*1318*t)+0.25*sin(2*PI*2636*t)+0.12*sin(2*PI*3954*t))*exp(-4*t)'],
        'sparkle' => ['magic', 'magical sparkle twinkle (glow, rainbow, wonder)', 1.2, '0.25*(sin(2*PI*2093*t)*between(t,0,0.3)*exp(-8*t)+sin(2*PI*2637*t)*between(t,0.15,0.5)*exp(-8*(t-0.15))+sin(2*PI*3136*t)*between(t,0.3,0.7)*exp(-8*(t-0.3))+sin(2*PI*4186*t)*between(t,0.45,1.2)*exp(-6*(t-0.45)))'],
        'tada' => ['cartoon', 'cheerful ta-da chord (success, case solved)', 1.6, '0.18*(sin(2*PI*523*t)+sin(2*PI*659*t)+sin(2*PI*784*t)+0.5*sin(2*PI*1046*t))*between(t,0.18,1.6)*exp(-1.6*(t-0.18))+0.18*sin(2*PI*392*t)*between(t,0,0.18)'],
        'tiptoe' => ['cartoon', 'sneaky tiptoe plucks (sneaking, being quiet)', 1.6, '0.35*(sin(2*PI*330*t)*between(t,0,0.2)*exp(-15*t)+sin(2*PI*294*t)*between(t,0.4,0.6)*exp(-15*(t-0.4))+sin(2*PI*330*t)*between(t,0.8,1)*exp(-15*(t-0.8))+sin(2*PI*262*t)*between(t,1.2,1.4)*exp(-15*(t-1.2)))'],
        'heartbeat' => ['cartoon', 'soft suspense heartbeat (tension, nervous)', 2.0, '0.7*sin(2*PI*55*t)*(exp(-20*mod(t,1))+0.7*exp(-20*mod(t-0.25,1))*gte(mod(t,1),0.25))'],
        'giggle_blips' => ['cartoon', 'tiny high giggle blips (cute laugh, funny moment)', 0.8, '0.3*sin(2*PI*(1500+300*sin(2*PI*9*t))*t)*abs(sin(2*PI*5*t))*exp(-2*t)'],
    ];

    /** Trang tải của BigSoundBank (mọi âm thanh CC0, dùng thương mại không cần ghi nguồn). */
    public const BSB = 'https://bigsoundbank.com/UPLOAD/ogg/%s.ogg';

    /**
     * Tiếng nhân vật: [nhóm, mô tả, mã BigSoundBank, dài tối đa (giây), loài (null: tiếng người, nhân vật nào cũng dùng được)].
     * Mỗi file gốc được tách theo khoảng lặng thành từng tiếng riêng (mỗi tiếng meo, mỗi tràng cười) làm các biến thể.
     *
     * @var array<string, array{0: string, 1: string, 2: list<string>, 3: float, 4: ?string}>
     */
    public const VOCALS = [
        'voice_laugh' => ['voice', 'a character laughs / giggles happily', ['1418', '1419', '1420', '0980'], 2.5, null],
        'voice_cry' => ['voice', 'a character cries / sobs (sad, hurt or disappointed)', ['2548', '2550', '2549'], 3.0, null],
        'voice_gasp' => ['voice', 'a character gasps "oh!" in surprise', ['3534'], 1.5, null],
        'voice_sigh' => ['voice', 'a character sighs (tired, relieved or disappointed)', ['1405', '1404'], 2.0, null],
        'voice_yawn' => ['voice', 'a character yawns (sleepy, waking up)', ['1406', '1407'], 3.0, null],
        'voice_sneeze' => ['voice', 'a character sneezes (achoo!)', ['3439', '0609'], 1.5, null],
        'voice_cough' => ['voice', 'a character coughs', ['1423'], 1.5, null],
        'voice_snore' => ['voice', 'a character snores while sleeping', ['1070'], 4.0, null],
        'voice_kiss' => ['voice', 'a kiss smack (affection)', ['2196'], 0.6, null],
        'voice_burp' => ['voice', 'a funny little burp after eating', ['1707'], 0.6, null],
        'voice_lick' => ['voice', 'licking (cleaning fur, licking milk or a treat)', ['0238'], 1.5, null],
        'voice_munch' => ['voice', 'crunchy munching (eating a cookie, apple or chips)', ['1113', '1430'], 2.0, null],
        'voice_tsk' => ['voice', 'tongue clicks (thinking, tsk tsk)', ['1038'], 1.5, null],
        'crowd_kids_laugh' => ['crowd', 'a group of children laughing together', ['1660'], 3.5, null],
        'crowd_cheer' => ['crowd', 'a group cheering and clapping (yay, celebration)', ['0236'], 3.0, null],
        'crowd_applause' => ['crowd', 'small audience applause', ['1765'], 4.0, null],
        'cat_meow' => ['animal', 'cat meows', ['1890', '1891', '1892', '1893'], 2.0, 'cat'],
        'cat_mew' => ['animal', 'tiny cute kitten mew', ['0494', '1472', '0390'], 1.2, 'cat'],
        'cat_purr' => ['animal', 'cat purring (content, cuddly)', ['0436'], 3.0, 'cat'],
        'dog_bark' => ['animal', 'dog barks', ['2954', '0916'], 1.5, 'dog'],
        'dog_yap' => ['animal', 'small dog yaps', ['3537'], 1.2, 'dog'],
        'dog_whine' => ['animal', 'dog whines / whimpers (begging, sad)', ['1544'], 2.0, 'dog'],
        'dog_pant' => ['animal', 'dog panting happily', ['1547'], 2.5, 'dog'],
        'duck_quack' => ['animal', 'duck quacks', ['0276'], 1.2, 'duck'],
        'duck_squeak' => ['animal', 'squeaky duck (cute, toy-like)', ['0417'], 1.0, 'duck'],
        'bird_peep' => ['animal', 'baby chick / small bird peeps', ['0672', '0430'], 1.5, 'bird'],
        'hen_cluck' => ['animal', 'hen clucks', ['0453'], 2.0, 'chicken'],
        'rooster_crow' => ['animal', 'rooster crows (morning)', ['0440'], 2.5, 'chicken'],
        'owl_hoot' => ['animal', 'owl hoots (night)', ['1763'], 2.5, 'owl'],
        'frog_croak' => ['animal', 'frog croaks', ['0819'], 1.5, 'frog'],
        'sheep_baa' => ['animal', 'sheep baas', ['2343'], 1.0, 'sheep'],
        'goat_bleat' => ['animal', 'goat bleats', ['0880'], 1.5, 'goat'],
        'cow_moo' => ['animal', 'cow moos', ['0546'], 2.5, 'cow'],
        'pig_oink' => ['animal', 'pig grunts / oinks', ['1658'], 1.5, 'pig'],
        'horse_neigh' => ['animal', 'horse neighs', ['0863'], 2.5, 'horse'],
        'hedgehog_squeak' => ['animal', 'tiny hedgehog squeak', ['0941'], 1.0, 'hedgehog'],
        'mouse_squeak' => ['animal', 'small squeaks (mouse, hamster, tiny critter)', ['1073'], 1.0, 'mouse'],
    ];

    /** Tối đa số biến thể mỗi mục tiếng nhân vật. */
    public const VOCAL_VARIANTS = 4;

    /** @return list<array{id: string, group: string, description: string, files: list<string>, duration_ms: int, source: string}> */
    public static function catalog(): array
    {
        $json = Media::disk()->get(self::CATALOG);

        return $json ? (array) json_decode($json, true) : [];
    }

    public static function installed(): bool
    {
        return Media::disk()->exists(self::CATALOG);
    }

    /** @return array<string, array> Mục theo id. */
    public static function byId(): array
    {
        return collect(self::catalog())->keyBy('id')->all();
    }

    /**
     * Tải gói Kenney, tạo hiệu ứng hoạt hình, tải tiếng nhân vật, ghi danh mục. Chạy lại an toàn (ghi đè).
     * $onlyVocals: giữ phần đã có, chỉ (tải lại) tiếng nhân vật.
     *
     * @param  callable(string): void|null  $log
     *
     * @throws RuntimeException
     */
    public static function install(?callable $log = null, bool $onlyVocals = false): int
    {
        if ($onlyVocals && self::installed()) {
            $log ??= fn () => null;
            $dir = sys_get_temp_dir().'/sound-library-'.bin2hex(random_bytes(4));
            File::ensureDirectoryExists($dir);
            try {
                $entries = [...array_values(array_filter(self::catalog(), fn ($e) => ! isset(self::VOCALS[$e['id']]))), ...self::vocals($dir, $log)];
                Media::disk()->put(self::CATALOG, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $log(count($entries).' mục âm thanh.');

                return count($entries);
            } finally {
                File::deleteDirectory($dir);
            }
        }
        $log ??= fn () => null;
        $dir = sys_get_temp_dir().'/sound-library-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        $entries = [];
        try {
            foreach (self::PACKS as $pack => $url) {
                $log("Tải gói {$pack}...");
                $zipPath = "{$dir}/{$pack}.zip";
                $response = Http::timeout(120)->sink($zipPath)->get($url);
                if ($response->failed()) {
                    throw new RuntimeException("Không tải được gói {$pack}.");
                }
                $zip = new ZipArchive;
                if ($zip->open($zipPath) !== true) {
                    throw new RuntimeException("Gói {$pack} hỏng.");
                }
                $groups = [];
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = (string) $zip->getNameIndex($i);
                    $base = basename($name);
                    if (! preg_match('/\.ogg$/i', $base) || preg_match(self::EXCLUDE, $base)) {
                        continue;
                    }
                    $group = preg_replace('/_?\d+$/', '', pathinfo($base, PATHINFO_FILENAME));
                    $target = self::ROOT."/kenney-{$pack}/".strtolower($base);
                    Media::disk()->put($target, (string) $zip->getFromIndex($i));
                    $groups[$group][] = $target;
                }
                $zip->close();
                foreach ($groups as $group => $files) {
                    sort($files);
                    [$kind, $description] = self::DESCRIPTIONS[$group] ?? ['ui', trim(strtolower(preg_replace('/(?<!^)[A-Z]/', ' $0', $group)))];
                    // Jingle: mỗi bản là một mục riêng (giai điệu khác nhau), các âm thanh khác gom biến thể.
                    if ($kind === 'jingle') {
                        foreach ($files as $n => $file) {
                            $entries[] = self::entry(strtolower($group).'_'.($n + 1), $kind, "{$description}, variation ".($n + 1), [$file], "Kenney {$pack} (CC0)", $dir);
                        }
                    } else {
                        $entries[] = self::entry(strtolower($pack.'_'.$group), $kind, $description, $files, "Kenney {$pack} (CC0)", $dir);
                    }
                }
            }

            $log('Tạo hiệu ứng hoạt hình...');
            foreach (self::CARTOON as $id => [$kind, $description, $seconds, $expr]) {
                $out = "{$dir}/{$id}.wav";
                self::synthesize($expr, $seconds, $out);
                $target = self::ROOT."/cartoon/{$id}.wav";
                Media::disk()->put($target, (string) File::get($out));
                $entries[] = self::entry("cartoon_{$id}", $kind, $description, [$target], 'Video Aff (tự tạo)', $dir);
            }

            $entries = [...$entries, ...self::vocals($dir, $log)];

            Media::disk()->put(self::CATALOG, json_encode(array_values($entries), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $log(count($entries).' mục âm thanh.');

            return count($entries);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * Tải tiếng nhân vật, tách từng tiếng theo khoảng lặng (cắt bớt nếu dài), chuẩn hoá âm lượng; mỗi tiếng là một biến thể.
     *
     * @return list<array>
     *
     * @throws RuntimeException
     */
    private static function vocals(string $dir, callable $log): array
    {
        $log('Tải tiếng nhân vật (BigSoundBank, CC0)...');
        $entries = [];
        $sources = [];
        foreach (self::VOCALS as $id => [$group, $description, $codes, $max, $species]) {
            $files = [];
            foreach ($codes as $code) {
                if (! isset($sources[$code])) {
                    $sources[$code] = "{$dir}/bsb-{$code}.ogg";
                    $response = Http::timeout(120)->sink($sources[$code])->get(sprintf(self::BSB, $code));
                    if ($response->failed()) {
                        throw new RuntimeException("Không tải được âm thanh {$code} từ BigSoundBank.");
                    }
                }
                foreach (self::chunks($sources[$code], $max) as [$start, $length]) {
                    if (count($files) >= self::VOCAL_VARIANTS) {
                        break 2;
                    }
                    $out = "{$dir}/{$id}-".count($files).'.wav';
                    self::cut($sources[$code], $start, $length, $out);
                    $target = self::ROOT."/vocals/{$id}-".(count($files) + 1).'.wav';
                    Media::disk()->put($target, (string) File::get($out));
                    $files[] = $target;
                }
            }
            if ($files !== []) {
                $entry = self::entry($id, $group, $description, $files, 'BigSoundBank (CC0)', $dir);
                $entries[] = [...$entry, 'species' => $species];
            }
        }
        $log(count($entries).' mục tiếng nhân vật.');

        return $entries;
    }

    /**
     * Các đoạn có tiếng (giây bắt đầu, độ dài) theo khoảng lặng; đoạn dài hơn $max thì cắt còn $max. Không thấy khoảng lặng: lấy $max giây đầu.
     *
     * @return list<array{0: float, 1: float}>
     */
    public static function chunks(string $file, float $max): array
    {
        $result = Process::timeout(120)->run([config('videoaff.ffmpeg'), '-v', 'info', '-nostdin', '-i', $file, '-af', 'silencedetect=noise=-38dB:d=0.25', '-f', 'null', '-']);
        $duration = (float) (preg_match('/Duration: (\d+):(\d+):([\d.]+)/', $result->errorOutput(), $m) ? $m[1] * 3600 + $m[2] * 60 + $m[3] : 0);
        preg_match_all('/silence_(start|end): (-?[\d.]+)/', $result->errorOutput(), $marks, PREG_SET_ORDER);
        $chunks = [];
        $start = 0.0;
        foreach ($marks as [, $kind, $at]) {
            if ($kind === 'start') {
                $length = (float) $at - $start;
                if ($length >= 0.15) {
                    $chunks[] = [max(0, $start - 0.03), min($max, $length + 0.08)];
                }
            } else {
                $start = (float) $at;
            }
        }
        if ($duration - $start >= 0.15 && (end($marks)[1] ?? 'end') === 'end') {
            $chunks[] = [max(0, $start - 0.03), min($max, $duration - $start)];
        }

        return $chunks ?: [[0.0, min($max, $duration ?: $max)]];
    }

    /** Cắt một đoạn, mờ vào / ra ngắn, chuẩn hoá đỉnh (stereo 44,1 kHz). */
    private static function cut(string $file, float $start, float $length, string $out): void
    {
        $fade = max(0, $length - 0.12);
        $result = Process::timeout(60)->run([config('videoaff.ffmpeg'), '-v', 'error', '-y', '-nostdin', '-ss', (string) round($start, 3), '-t', (string) round($length, 3), '-i', $file,
            '-af', "afade=t=in:d=0.02,afade=t=out:st={$fade}:d=0.12,dynaudnorm=p=0.9:m=8,aresample=44100,aformat=channel_layouts=stereo", $out]);
        if ($result->failed()) {
            throw new RuntimeException('Cắt tiếng nhân vật lỗi: '.mb_substr($result->errorOutput(), -300));
        }
    }

    private static function entry(string $id, string $group, string $description, array $files, string $source, string $dir): array
    {
        $probe = "{$dir}/probe.".pathinfo($files[0], PATHINFO_EXTENSION);
        File::put($probe, (string) Media::disk()->get($files[0]));
        $result = Process::timeout(30)->run([config('videoaff.ffprobe'), '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $probe]);

        return [
            'id' => $id, 'group' => $group, 'description' => $description, 'files' => $files,
            'duration_ms' => (int) round((float) trim($result->output()) * 1000), 'source' => $source,
        ];
    }

    /** "noise:giây:tần số:đỉnh lúc:kết thúc lúc" = tiếng gió lọc dải (whoosh); còn lại là biểu thức aevalsrc. */
    private static function synthesize(string $expr, float $seconds, string $out): void
    {
        if (str_starts_with($expr, 'noise:')) {
            [, $d, $f, $peak, $end] = explode(':', $expr);
            $args = ['-f', 'lavfi', '-i', "anoisesrc=color=pink:duration={$d}:sample_rate=44100:amplitude=0.9",
                '-af', "bandpass=f={$f}:width_type=o:w=1.6,afade=t=in:st=0:d={$peak}:curve=qsin,afade=t=out:st={$peak}:d=".max(0.05, (float) $end - (float) $peak).':curve=qsin,volume=1.6,aformat=channel_layouts=stereo'];
        } else {
            $args = ['-f', 'lavfi', '-i', "aevalsrc='{$expr}':s=44100:d={$seconds}", '-af', 'aformat=channel_layouts=stereo,afade=t=out:st='.max(0, $seconds - 0.05).':d=0.05'];
        }
        $result = Process::timeout(60)->run([config('videoaff.ffmpeg'), '-v', 'error', '-y', ...$args, $out]);
        if ($result->failed()) {
            throw new RuntimeException('Tạo hiệu ứng lỗi: '.mb_substr($result->errorOutput(), -300));
        }
    }
}
