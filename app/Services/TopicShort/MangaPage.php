<?php

namespace App\Services\TopicShort;

use App\Models\CharacterPortrait;
use App\Models\MangaPanel;
use App\Models\TopicShortScene;
use App\Support\DragonBall;
use App\Support\Media;
use GdImage;
use Illuminate\Support\Collection;

/**
 * Trang manga dọc 1080x1920 cho một cảnh: 1–3 khung xếp từ trên xuống, mỗi khung là ảnh thư viện nhân vật đúng tư thế
 * (chưa có thì dùng mặt thường E1), nền trắng, viền đen như trang truyện. Không tốn Credits, không gọi Muse.
 * Cảnh có khung manga thật (manga_panels) thì ghép các khung đó trước.
 */
final class MangaPage
{
    public const WIDTH = 1080;

    public const HEIGHT = 1920;

    private const MARGIN = 40;

    private const GUTTER = 28;

    private const BORDER = 7;

    /**
     * Ảnh thư viện cho từng khung: đúng tư thế, không có thì mặt thường; null khi nhân vật chưa có ảnh nào.
     *
     * @return list<array{panel: array, portrait: ?CharacterPortrait, exact: bool}>
     */
    public static function resolve(TopicShortScene $scene, ?Collection $library = null): array
    {
        $userId = $scene->topicShort->user_id;
        $library ??= CharacterPortrait::where('user_id', $userId)->where('status', 'ready')->whereNotNull('image_path')
            ->whereIn('character', array_column($scene->panels ?? [], 'character'))->get();

        return array_map(function (array $panel) use ($library) {
            $mine = $library->where('character', $panel['character']);
            $exact = $mine->firstWhere('expression', $panel['pose']);

            return ['panel' => $panel, 'portrait' => $exact ?? $mine->firstWhere('expression', 'E1'), 'exact' => $exact !== null];
        }, $scene->panels ?? []);
    }

    /** Ghép trang và gắn làm ảnh cảnh. Trả false khi không khung nào có ảnh (cảnh giữ ảnh cũ). */
    public static function compose(TopicShortScene $scene): bool
    {
        if (($scene->manga_panels ?? []) !== [] && self::composeReal($scene)) {
            return true;
        }
        $images = [];
        foreach (self::resolve($scene) as $item) {
            $contents = $item['portrait'] ? Media::disk()->get($item['portrait']->image_path) : null;
            $image = $contents ? @imagecreatefromstring($contents) : false;
            if ($image instanceof GdImage) {
                $images[] = $image;
            }
        }
        if ($images === []) {
            return false;
        }

        $page = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagefill($page, 0, 0, imagecolorallocate($page, 255, 255, 255));
        $black = imagecolorallocate($page, 0, 0, 0);
        $count = count($images);
        $width = self::WIDTH - 2 * self::MARGIN;
        $height = intdiv(self::HEIGHT - 2 * self::MARGIN - ($count - 1) * self::GUTTER, $count);
        foreach ($images as $i => $image) {
            $x = self::MARGIN;
            $y = self::MARGIN + $i * ($height + self::GUTTER);
            imagefilledrectangle($page, $x, $y, $x + $width - 1, $y + $height - 1, $black);
            self::cover($page, $image, $x + self::BORDER, $y + self::BORDER, $width - 2 * self::BORDER, $height - 2 * self::BORDER);
            imagedestroy($image);
        }
        self::save($scene, $page);

        return true;
    }

    /**
     * Trang từ khung manga thật: xếp từ trên xuống, mỗi khung giữ nguyên tỉ lệ (không cắt mất chữ / nhân vật),
     * chiều cao chia theo tỉ lệ khung, nền trắng như trang truyện. False khi không đọc được khung nào.
     */
    public static function composeReal(TopicShortScene $scene): bool
    {
        $ids = array_map('intval', $scene->manga_panels ?? []);
        $panels = MangaPanel::where('user_id', $scene->topicShort->user_id)->whereIn('id', $ids)->get()->sortBy(fn ($p) => array_search($p->id, $ids, true));
        $images = [];
        foreach ($panels as $panel) {
            $contents = Media::disk()->get($panel->image_path);
            $image = $contents ? @imagecreatefromstring($contents) : false;
            if ($image instanceof GdImage) {
                $images[] = $image;
            }
        }
        if ($images === []) {
            return false;
        }

        $page = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagefill($page, 0, 0, imagecolorallocate($page, 255, 255, 255));
        $width = self::WIDTH - 2 * self::MARGIN;
        $free = self::HEIGHT - 2 * self::MARGIN - (count($images) - 1) * self::GUTTER;
        // Chiều cao tự nhiên khi khung rộng hết trang; quá trang thì co tất cả theo cùng tỉ lệ.
        $natural = array_map(fn (GdImage $image) => imagesy($image) * $width / imagesx($image), $images);
        $shrink = min(1, $free / array_sum($natural));
        $y = self::MARGIN + (int) round(($free - array_sum($natural) * $shrink) / 2);
        foreach ($images as $i => $image) {
            $h = (int) round($natural[$i] * $shrink);
            $w = (int) round($width * $shrink);
            imagecopyresampled($page, $image, intdiv(self::WIDTH - $w, 2), $y, 0, 0, $w, $h, imagesx($image), imagesy($image));
            $y += $h + self::GUTTER;
            imagedestroy($image);
        }
        self::save($scene, $page);

        return true;
    }

    private static function save(TopicShortScene $scene, GdImage $page): void
    {
        ob_start();
        imagepng($page, null, 6);
        $contents = (string) ob_get_clean();
        imagedestroy($page);

        $path = "topic-shorts/{$scene->topic_short_id}/scene-{$scene->id}-page-".substr(md5($contents), 0, 8).'.png';
        Media::disk()->put($path, $contents);
        $old = $scene->image_path;
        $scene->update(['image_source' => 'page', 'image_status' => 'ready', 'image_path' => $path, 'image_url' => null, 'image_error' => null, 'request_id' => null]);
        $old && $old !== $path && Media::delete($old);
    }

    /** Ghép trang; không ghép được thì cảnh báo lỗi kèm cách sửa (không tìm wiki, không tự gọi Muse tốn Credits). */
    public static function composeOrExplain(TopicShortScene $scene): bool
    {
        if (self::compose($scene)) {
            return true;
        }
        $names = collect($scene->panels ?? [])->map(fn ($panel) => DragonBall::CHARACTERS[$panel['character']][0] ?? null)->filter()->unique()->implode(', ');
        $scene->update([
            'image_status' => 'failed',
            'image_error' => $names !== ''
                ? "Thư viện chưa có ảnh {$names}. Bấm \"Vẽ khung còn thiếu\" (xong sẽ tự ghép trang), tải ảnh lên hoặc cho Muse vẽ."
                : 'Cảnh không có nhân vật trong thư viện. Tải ảnh lên hoặc cho Muse vẽ.',
        ]);

        return false;
    }

    /**
     * Ảnh thư viện mới sẵn sàng: ghép lại trang manga của các cảnh dùng nhân vật này (đúng tư thế, hoặc đang tạm dùng mặt thường).
     * Bỏ qua cảnh người dùng đã chọn ảnh khác và short đang dựng video.
     */
    public static function refreshFor(CharacterPortrait $portrait): int
    {
        $scenes = TopicShortScene::whereNotNull('panels')
            ->where(fn ($q) => $q->where('image_source', 'page')->orWhereNull('image_source')->orWhere('image_status', 'failed'))
            ->whereHas('topicShort', fn ($q) => $q->where('user_id', $portrait->user_id)->where('status', 'ready')->where(fn ($q) => $q->whereNull('video_status')->orWhere('video_status', '!=', 'rendering')))
            ->latest('id')->limit(200)->get()
            ->filter(fn (TopicShortScene $scene) => collect($scene->panels ?? [])->contains(
                fn ($panel) => $panel['character'] === $portrait->character && ($panel['pose'] === $portrait->expression || $portrait->expression === 'E1'),
            ));

        return $scenes->filter(fn (TopicShortScene $scene) => self::compose($scene))->count();
    }

    /**
     * Khung còn thiếu ảnh đúng tư thế trong các cảnh (gộp trùng), kèm trạng thái ô thư viện.
     *
     * @param  iterable<TopicShortScene>  $scenes
     * @return list<array{character: string, pose: string, name: string, label: string, status: string}>
     */
    public static function missing(iterable $scenes, int $userId): array
    {
        // Cảnh đã dùng khung manga thật thì không cần ảnh thư viện.
        $wanted = collect($scenes)->filter(fn (TopicShortScene $scene) => ($scene->manga_panels ?? []) === [])->flatMap(fn (TopicShortScene $scene) => $scene->panels ?? [])
            ->map(fn ($panel) => ['character' => $panel['character'], 'pose' => $panel['pose']])->unique(fn ($p) => "{$p['character']}:{$p['pose']}");
        if ($wanted->isEmpty()) {
            return [];
        }
        $existing = CharacterPortrait::where('user_id', $userId)->whereIn('character', $wanted->pluck('character')->unique())->get()
            ->keyBy(fn (CharacterPortrait $p) => "{$p->character}:{$p->expression}");

        return $wanted->filter(fn ($p) => $existing->get("{$p['character']}:{$p['pose']}")?->status !== 'ready')
            ->map(fn ($p) => [
                ...$p,
                'name' => DragonBall::CHARACTERS[$p['character']][0] ?? $p['character'],
                'label' => DragonBall::pose($p['character'], $p['pose'])[0] ?? $p['pose'],
                'status' => $existing->get("{$p['character']}:{$p['pose']}")?->status ?? 'none',
            ])->values()->all();
    }

    /** Thu / phóng ảnh phủ kín khung rồi cắt phần thừa (giữ phần trên, nơi thường là mặt nhân vật). */
    private static function cover(GdImage $page, GdImage $image, int $x, int $y, int $width, int $height): void
    {
        $sw = imagesx($image);
        $sh = imagesy($image);
        $scale = max($width / $sw, $height / $sh);
        $cw = (int) round($width / $scale);
        $ch = (int) round($height / $scale);
        $sx = intdiv($sw - $cw, 2);
        // Khung thấp: lấy phần trên 1/3 thay vì giữa để không cắt mất mặt.
        $sy = (int) round(($sh - $ch) / 3);
        imagecopyresampled($page, $image, $x, $y, max(0, $sx), max(0, $sy), $width, $height, min($cw, $sw), min($ch, $sh));
    }
}
