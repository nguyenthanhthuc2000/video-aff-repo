<?php

namespace App\Services\Manga;

use App\Models\MangaPanel;
use App\Models\MangaScan;
use App\Services\Claude\AiUsage;
use App\Services\Film\FilmClaude;
use App\Services\Script\ScriptWriterException;
use App\Support\DragonBall;
use App\Support\Media;
use GdImage;

/** Claude xem các khung của một trang: nhân vật, chuyện đang xảy ra, cảm xúc, khung có dùng làm ảnh cảnh được không. */
final class PanelTagger
{
    public const MOODS = ['tense', 'sad', 'epic', 'funny', 'calm'];

    private const SYSTEM = <<<'TXT'
Bạn xem các khung truyện tranh Dragon Ball (đã cắt sẵn từ một trang, theo thứ tự đọc) và ghi lại để sau này tìm khung hợp với lời kể video.
Với mỗi khung:
- characters: tên tiếng Anh các nhân vật nhìn thấy rõ (Goku, Vegeta, Frieza, Krillin, Gohan, Piccolo...), đúng tên quen thuộc; không đoán nhân vật không thấy.
- description: 1 câu tiếng Việt tả chuyện đang xảy ra trong khung (ai làm gì, biểu cảm, bối cảnh). Có lời thoại thì tóm ý lời thoại.
- mood: tense (căng thẳng), sad (buồn), epic (hoành tráng, chiến đấu, biến hình), funny (hài), calm (bình thường).
- useful: false nếu khung gần như chỉ có chữ, hiệu ứng, nền trống hoặc quá nhỏ để làm ảnh minh hoạ; còn lại true.
TXT;

    /** @throws ScriptWriterException */
    public static function tag(MangaScan $scan): void
    {
        $panels = $scan->panels()->get();
        if ($panels->isEmpty()) {
            return;
        }
        $images = $panels->map(fn (MangaPanel $panel) => ['data' => self::small((string) Media::disk()->get($panel->image_path)), 'media_type' => 'image/jpeg'])->all();
        $text = 'Trang truyện'.($scan->chapter ? " thuộc {$scan->chapter}" : '').", {$panels->count()} khung theo thứ tự ảnh gửi kèm (khung 1 là ảnh đầu tiên).";
        $data = AiUsage::withContext(['user_id' => $scan->user_id, 'subject' => "manga_scan:{$scan->id}"], fn () => FilmClaude::json(
            self::SYSTEM, $text, self::schema(), 'topic_short', "topic_short:manga_scan:{$scan->id}", 3000, fn () => self::sample($panels->count()), $images,
        ));

        foreach ($data['panels'] ?? [] as $item) {
            $panel = $panels->get(((int) ($item['index'] ?? 0)) - 1);
            if (! $panel) {
                continue;
            }
            $keys = array_values(array_unique(array_filter(array_map(fn ($name) => DragonBall::keyFor((string) $name), (array) ($item['characters'] ?? [])))));
            $panel->update([
                'characters' => $keys,
                'description' => mb_substr(trim((string) ($item['description'] ?? '')), 0, 500) ?: null,
                'mood' => in_array($item['mood'] ?? null, self::MOODS, true) ? $item['mood'] : null,
                'useful' => (bool) ($item['useful'] ?? true),
                'tagged_at' => now(),
            ]);
        }
    }

    /** Thu khung về tối đa 800px (đủ để Claude nhận ra nhân vật, gửi nhẹ). */
    private static function small(string $contents): string
    {
        $image = @imagecreatefromstring($contents);
        if (! $image instanceof GdImage) {
            return $contents;
        }
        $scale = min(1, 800 / max(imagesx($image), imagesy($image)));
        $resized = $scale < 1 ? imagescale($image, (int) round(imagesx($image) * $scale)) : $image;
        ob_start();
        imagejpeg($resized ?: $image, null, 85);

        return (string) ob_get_clean();
    }

    private static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['panels'],
            'properties' => [
                'panels' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['index', 'characters', 'description', 'mood', 'useful'],
                    'properties' => [
                        'index' => ['type' => 'integer'],
                        'characters' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'description' => ['type' => 'string'],
                        'mood' => ['type' => 'string', 'enum' => self::MOODS],
                        'useful' => ['type' => 'boolean'],
                    ],
                ]],
            ],
        ];
    }

    private static function sample(int $count): array
    {
        return ['panels' => array_map(fn ($i) => [
            'index' => $i,
            'characters' => $i === 1 ? ['Goku', 'Frieza'] : ['Vegeta'],
            'description' => $i === 1 ? 'Goku giận dữ nhìn Frieza trên Namek.' : 'Vegeta nhếch mép cười.',
            'mood' => $i === 1 ? 'tense' : 'calm',
            'useful' => true,
        ], range(1, $count))];
    }
}
