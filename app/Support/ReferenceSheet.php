<?php

namespace App\Support;

/**
 * Ghép nhiều ảnh sản phẩm thành một ảnh lưới: Muse nhận tối đa 4 ảnh mỗi yêu cầu (người mẫu, bối cảnh, sản phẩm),
 * nên ảnh sản phẩm vượt số suất được gộp vào một ảnh để Muse vẫn thấy đủ mọi ảnh đã chọn.
 */
final class ReferenceSheet
{
    public const MAX_ATTACHMENTS = 4;

    private const CELL = 768;

    private const GAP = 16;

    /**
     * Chia ảnh sản phẩm vào $slots suất: đủ suất thì gửi từng ảnh; thiếu thì các suất đầu gửi riêng (ảnh chính trước), suất cuối là ảnh lưới phần còn lại.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public static function fit(array $paths, int $slots): array
    {
        $paths = array_values($paths);
        if ($slots < 1 || count($paths) <= $slots) {
            return array_slice($paths, 0, max($slots, 0));
        }
        $single = array_slice($paths, 0, $slots - 1);
        $sheet = self::combine(array_slice($paths, $slots - 1));

        return $sheet === null ? array_slice($paths, 0, $slots) : [...$single, $sheet];
    }

    /** Câu dặn Muse khi có ảnh lưới. */
    public const NOTE = 'One attached product image is a grid of several photos of the same product (different views, details or colors shown together); '
        .'use all of them for the product details, and do not draw a grid or several panels in the result.';

    public static function isSheet(string $path): bool
    {
        return str_starts_with($path, 'reference-sheets/');
    }

    /** Ảnh lưới (JPEG) của các ảnh, lưu theo nội dung nên gọi lại không tạo trùng; null nếu không đọc được ảnh nào. */
    public static function combine(array $paths): ?string
    {
        $disk = Media::disk();
        $target = 'reference-sheets/'.md5(implode('|', $paths)).'.jpg';
        if ($disk->exists($target)) {
            return $target;
        }

        $images = [];
        foreach ($paths as $path) {
            $contents = $disk->get($path);
            $image = $contents ? @imagecreatefromstring($contents) : false;
            if ($image !== false) {
                $images[] = $image;
            }
        }
        if ($images === []) {
            return null;
        }

        $cols = (int) ceil(sqrt(count($images)));
        $rows = (int) ceil(count($images) / $cols);
        $width = $cols * self::CELL + ($cols + 1) * self::GAP;
        $height = $rows * self::CELL + ($rows + 1) * self::GAP;
        $sheet = imagecreatetruecolor($width, $height);
        imagefill($sheet, 0, 0, imagecolorallocate($sheet, 255, 255, 255));

        foreach ($images as $i => $image) {
            // Giữ tỉ lệ, căn giữa trong ô vuông.
            $scale = min(self::CELL / imagesx($image), self::CELL / imagesy($image));
            $w = (int) round(imagesx($image) * $scale);
            $h = (int) round(imagesy($image) * $scale);
            $x = self::GAP + ($i % $cols) * (self::CELL + self::GAP) + intdiv(self::CELL - $w, 2);
            $y = self::GAP + intdiv($i, $cols) * (self::CELL + self::GAP) + intdiv(self::CELL - $h, 2);
            imagecopyresampled($sheet, $image, $x, $y, 0, 0, $w, $h, imagesx($image), imagesy($image));
        }

        ob_start();
        imagejpeg($sheet, null, 88);
        $disk->put($target, (string) ob_get_clean());

        return $target;
    }
}
