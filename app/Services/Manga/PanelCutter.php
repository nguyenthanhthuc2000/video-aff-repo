<?php

namespace App\Services\Manga;

use GdImage;

/**
 * Cắt trang manga thành từng khung theo khe trắng giữa các khung (GD, không tốn gì).
 * Tách hàng ngang trước, trong mỗi hàng tách cột, lặp vài tầng; thứ tự đọc manga: trên xuống, phải sang trái.
 * Không tìm được khe nào (trang một khung, hoặc tranh tràn lề) thì trả cả trang.
 */
final class PanelCutter
{
    /** Ảnh thu nhỏ để dò khe (nhanh, đủ chính xác). */
    private const PROBE_WIDTH = 500;

    /** Điểm sáng hơn mức này coi là giấy trắng. */
    private const PAPER = 215;

    /** Khung nhỏ hơn tỉ lệ này của trang bị bỏ (chữ lẻ, số trang). */
    private const MIN_SIDE = 0.12;

    /**
     * @return list<array{x: int, y: int, width: int, height: int}> toạ độ trên ảnh gốc, theo thứ tự đọc
     */
    public static function boxes(GdImage $image, bool $rightToLeft = true): array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, self::PROBE_WIDTH / $width);
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));
        $probe = imagecreatetruecolor($w, $h);
        imagecopyresampled($probe, $image, 0, 0, 0, 0, $w, $h, $width, $height);

        $ink = [];
        for ($y = 0; $y < $h; $y++) {
            $row = [];
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($probe, $x, $y);
                $row[] = ((($rgb >> 16) & 255) * 299 + (($rgb >> 8) & 255) * 587 + ($rgb & 255) * 114) / 1000 < self::PAPER;
            }
            $ink[] = $row;
        }
        imagedestroy($probe);

        $leaves = [];
        self::split($ink, [0, 0, $w, $h], true, 4, $rightToLeft, $leaves);
        $boxes = [];
        foreach ($leaves as $box) {
            $box = self::trim($ink, $box);
            if ($box === null || $w * self::MIN_SIDE > $box[2] - $box[0] || $h * self::MIN_SIDE * 0.6 > $box[3] - $box[1]) {
                continue;
            }
            $boxes[] = $box;
        }
        if (count($boxes) < 2) {
            $boxes = [self::trim($ink, [0, 0, $w, $h]) ?? [0, 0, $w, $h]];
        }

        // Đổi về toạ độ ảnh gốc, chừa 3px để không cắt mất viền khung.
        return array_map(function (array $box) use ($scale, $width, $height) {
            $x0 = max(0, (int) floor($box[0] / $scale) - 3);
            $y0 = max(0, (int) floor($box[1] / $scale) - 3);
            $x1 = min($width, (int) ceil($box[2] / $scale) + 3);
            $y1 = min($height, (int) ceil($box[3] / $scale) + 3);

            return ['x' => $x0, 'y' => $y0, 'width' => $x1 - $x0, 'height' => $y1 - $y0];
        }, $boxes);
    }

    /** @param array{0: int, 1: int, 2: int, 3: int} $box [x0, y0, x1, y1) */
    private static function split(array $ink, array $box, bool $horizontal, int $depth, bool $rightToLeft, array &$leaves): void
    {
        $parts = $depth > 0 ? self::cuts($ink, $box, $horizontal) : [];
        if (count($parts) < 2 && $depth > 0) {
            // Không tách được theo chiều này: thử chiều kia (một lần).
            $parts = self::cuts($ink, $box, ! $horizontal);
            $horizontal = ! $horizontal;
        }
        if (count($parts) < 2) {
            $leaves[] = $box;

            return;
        }
        if (! $horizontal && $rightToLeft) {
            $parts = array_reverse($parts);
        }
        foreach ($parts as $part) {
            self::split($ink, $part, ! $horizontal, $depth - 1, $rightToLeft, $leaves);
        }
    }

    /**
     * Các đoạn giữa những khe trắng chạy suốt vùng (hàng ngang khi $horizontal, cột dọc khi ngược lại).
     *
     * @return list<array{0: int, 1: int, 2: int, 3: int}>
     */
    private static function cuts(array $ink, array $box, bool $horizontal): array
    {
        [$x0, $y0, $x1, $y1] = $box;
        [$from, $to, $across] = $horizontal ? [$y0, $y1, $x1 - $x0] : [$x0, $x1, $y1 - $y0];
        $allowed = max(1, (int) ($across * 0.01));
        $parts = [];
        $start = null;
        for ($i = $from; $i <= $to; $i++) {
            $count = 0;
            if ($i < $to) {
                if ($horizontal) {
                    for ($x = $x0; $x < $x1 && $count <= $allowed; $x++) {
                        $count += $ink[$i][$x] ? 1 : 0;
                    }
                } else {
                    for ($y = $y0; $y < $y1 && $count <= $allowed; $y++) {
                        $count += $ink[$y][$i] ? 1 : 0;
                    }
                }
            }
            $blank = $i === $to || $count <= $allowed;
            if (! $blank && $start === null) {
                $start = $i;
            } elseif ($blank && $start !== null) {
                // Bỏ vệt mực mảnh (chữ lẻ ngoài khung) cao / rộng dưới 3% vùng.
                if ($i - $start >= ($to - $from) * 0.03) {
                    $parts[] = $horizontal ? [$x0, $start, $x1, $i] : [$start, $y0, $i, $y1];
                }
                $start = null;
            }
        }

        return $parts;
    }

    /** Thu vùng về khung bao phần có mực. */
    private static function trim(array $ink, array $box): ?array
    {
        [$x0, $y0, $x1, $y1] = $box;
        $minX = $x1;
        $minY = $y1;
        $maxX = -1;
        $maxY = -1;
        for ($y = $y0; $y < $y1; $y++) {
            for ($x = $x0; $x < $x1; $x++) {
                if ($ink[$y][$x]) {
                    $minX = min($minX, $x);
                    $maxX = max($maxX, $x);
                    $minY = min($minY, $y);
                    $maxY = max($maxY, $y);
                }
            }
        }

        return $maxX < 0 ? null : [$minX, $minY, $maxX + 1, $maxY + 1];
    }
}
