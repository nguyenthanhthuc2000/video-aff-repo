<?php

namespace App\Support;

use App\Models\ProductCategory;
use App\Models\VideoProject;

/**
 * Góc quay sản phẩm kiểu người review thật: mỗi cảnh một góc (toàn thân, cận chi tiết, chất liệu, nghiêng, mặt sau,
 * chuyển động...) để video thấy đủ sản phẩm, thay vì nhiều cảnh cùng một kiểu đứng tạo dáng.
 */
final class ProductCoverage
{
    /**
     * label: tên hiển thị; image: khung hình khi tạo ảnh; move: hành động / máy quay khi tạo clip; close: cận cảnh (không cần thấy mặt).
     *
     * @var array<string, array{label: string, image: string, move: string, close: bool}>
     */
    public const ANGLES = [
        // Quần áo.
        'front_full' => [
            'label' => 'Toàn thân chính diện',
            'image' => 'Full-body shot from the front at eye level, the whole garment visible from neckline to hem.',
            'move' => 'She faces the camera and shifts her weight naturally, showing the whole front of the garment.',
            'close' => false,
        ],
        'top_detail' => [
            'label' => 'Cận cổ áo / phần trên',
            'image' => 'Close-up from the chest up: the neckline, collar, buttons and details of the upper part of the garment are sharp and fill most of the frame.',
            'move' => 'The camera moves in close; she lightly touches the neckline and details with her fingers to point them out.',
            'close' => true,
        ],
        'fabric' => [
            'label' => 'Cận chất vải',
            'image' => 'Macro close-up of the fabric: her fingers pinch or smooth the material so its texture, weave, thickness and stitching are clearly visible. The face is not needed in this shot.',
            'move' => 'Very close on the fabric; her fingers gently rub, pinch and slightly stretch it to show the texture and how soft it is.',
            'close' => true,
        ],
        'side' => [
            'label' => 'Góc nghiêng',
            'image' => 'Three-quarter side view, full body or knee-up, showing the silhouette and how the garment fits from the side.',
            'move' => 'She turns slowly to the side and back so the silhouette and fit are visible from the side.',
            'close' => false,
        ],
        'back' => [
            'label' => 'Mặt sau',
            'image' => 'View from behind as she glances back over her shoulder, showing the back of the garment. If the product photos do not show the back, keep the back plain and consistent with the front (same color, fabric and pattern); do not invent details.',
            'move' => 'She turns around to show the back of the garment, glances over her shoulder, then turns back toward the camera.',
            'close' => false,
        ],
        'bottom_detail' => [
            'label' => 'Cận chi tiết (tay áo, eo, gấu)',
            'image' => 'Close-up of the sleeves, waist, hem or other small details of the garment, sharp and filling most of the frame.',
            'move' => 'The camera tilts down along the garment, close on the sleeves, waist and hem, while her hand shows the details.',
            'close' => true,
        ],
        'motion' => [
            'label' => 'Đi lại cho thấy độ rủ',
            'image' => 'Full-body candid shot while she walks toward the camera, the fabric moving naturally.',
            'move' => 'She walks a few steps toward the camera and does a small turn so the fabric moves and flows naturally.',
            'close' => false,
        ],
        'styling' => [
            'label' => 'Phối đồ trước gương',
            'image' => 'Full-body mirror selfie holding a phone, showing the complete outfit with the product.',
            'move' => 'She poses casually in front of the mirror, adjusts the outfit and checks herself from both sides.',
            'close' => false,
        ],
        // Giày dép.
        'shoes_on' => [
            'label' => 'Mang trên chân',
            'image' => 'Low close shot of her feet wearing the shoes, from the front, the whole shoe visible.',
            'move' => 'Close on her feet; she shifts her weight and turns one foot to show the shoe from the front and side.',
            'close' => true,
        ],
        'shoes_side' => [
            'label' => 'Ngang thân giày',
            'image' => 'Side profile of the shoe on her foot, showing the full shape, heel and sole thickness.',
            'move' => 'She lifts her heel slightly and turns her foot so the side profile, heel and sole are visible.',
            'close' => true,
        ],
        'shoes_hand' => [
            'label' => 'Cầm trên tay: đế và chất liệu',
            'image' => 'She holds one shoe up near the camera, showing the sole and the material up close.',
            'move' => 'She holds one shoe in her hand, turns it to show the sole, the inside and the material.',
            'close' => true,
        ],
        'shoes_walk' => [
            'label' => 'Đi thử',
            'image' => 'Shot from the knees down while she walks, the shoes clearly visible.',
            'move' => 'The camera follows her feet as she walks a few natural steps.',
            'close' => false,
        ],
        // Túi.
        'bag_carry' => [
            'label' => 'Đeo toàn thân',
            'image' => 'Full-body shot carrying the bag as intended, the whole bag clearly visible.',
            'move' => 'She walks a few steps carrying the bag, then stops and adjusts the strap.',
            'close' => false,
        ],
        'bag_front' => [
            'label' => 'Cận mặt trước túi',
            'image' => 'Close-up of the front of the bag held toward the camera: logo, hardware and material sharp.',
            'move' => 'She holds the bag up close to the camera and slowly turns it to show the front and the hardware.',
            'close' => true,
        ],
        'bag_inside' => [
            'label' => 'Mở túi xem bên trong',
            'image' => 'Close-up of her hands opening the bag to show the inside and how much it holds. Keep the inside simple if the photos do not show it.',
            'move' => 'She opens the bag, shows the inside to the camera and closes it again.',
            'close' => true,
        ],
        'bag_side' => [
            'label' => 'Nghiêng / mặt sau túi',
            'image' => 'The bag seen from the side and back, showing its thickness and strap.',
            'move' => 'She turns the bag around to show its side, back and strap.',
            'close' => true,
        ],
        // Phụ kiện đeo.
        'worn_close' => [
            'label' => 'Cận khi đeo',
            'image' => 'Close-up of the accessory being worn, sharp and filling much of the frame.',
            'move' => 'Close on the accessory; she turns slightly so the light catches it and it is visible from both sides.',
            'close' => true,
        ],
        'held_detail' => [
            'label' => 'Cầm trên tay xem chi tiết',
            'image' => 'She holds the product in her palm close to the camera, the details and material sharp.',
            'move' => 'She holds the product in her hand near the camera and turns it slowly to show every detail.',
            'close' => true,
        ],
        'worn_full' => [
            'label' => 'Tổng thể khi đeo / dùng',
            'image' => 'Waist-up or full-body shot wearing or using the product with a matching outfit.',
            'move' => 'She moves naturally while wearing or using the product, showing how it looks overall.',
            'close' => false,
        ],
        // Mỹ phẩm, đồ công nghệ, khác.
        'held_front' => [
            'label' => 'Cầm giới thiệu',
            'image' => 'She holds the product up next to her face toward the camera, label facing the camera.',
            'move' => 'She raises the product next to her face, label to the camera, and smiles.',
            'close' => false,
        ],
        'label_close' => [
            'label' => 'Cận bao bì / nhãn',
            'image' => 'Close-up of the product packaging and label, sharp and readable, held in her hand.',
            'move' => 'Close on the product in her hand; she turns it slowly to show the label and every side.',
            'close' => true,
        ],
        'texture' => [
            'label' => 'Thử kết cấu',
            'image' => 'Close-up of a swatch of the product on the back of her hand (or the product being opened) showing its texture.',
            'move' => 'She opens the product and swatches or uses a little of it so the texture is visible up close.',
            'close' => true,
        ],
        'in_use' => [
            'label' => 'Đang dùng',
            'image' => 'She is using the product the way it is meant to be used, the product clearly visible.',
            'move' => 'She uses the product the way it is meant to be used, slowly and clearly.',
            'close' => false,
        ],
    ];

    /** Thứ tự góc theo loại sản phẩm: góc đầu mở video, góc cuối cho cảnh kêu gọi mua, các góc giữa theo độ ưu tiên. */
    private const PLANS = [
        'clothing' => ['front_full', 'top_detail', 'fabric', 'back', 'side', 'motion', 'bottom_detail', 'styling'],
        'shoes' => ['worn_full', 'shoes_on', 'shoes_side', 'shoes_hand', 'shoes_walk', 'styling'],
        'bag' => ['bag_carry', 'bag_front', 'bag_inside', 'bag_side', 'held_detail', 'styling'],
        'wearable' => ['worn_full', 'worn_close', 'held_detail', 'in_use', 'styling'],
        'beauty' => ['held_front', 'label_close', 'texture', 'in_use', 'worn_full'],
        'phone' => ['held_front', 'held_detail', 'label_close', 'in_use', 'worn_full'],
        'other' => ['held_front', 'held_detail', 'label_close', 'in_use', 'worn_full'],
    ];

    /** Có chia góc không: video mẫu thì góc máy theo video mẫu, video biến hình có cảnh chưa mặc sản phẩm. */
    public static function applies(VideoProject $project): bool
    {
        return ! $project->isTransformation() && $project->sample_video_id === null && $project->shotPieces() === [];
    }

    /**
     * Góc của từng cảnh: cảnh 1 góc mở, cảnh cuối góc kết, các cảnh giữa lấy lần lượt các góc còn lại (đủ góc trước khi lặp).
     *
     * @return list<?string>
     */
    public static function plan(VideoProject $project, int $scenes): array
    {
        if (! self::applies($project) || $scenes < 1) {
            return array_fill(0, max(0, $scenes), null);
        }

        $order = self::PLANS[self::kind($project)];
        if ($scenes === 1) {
            return [$order[0]];
        }

        $middle = array_slice($order, 1, -1);
        $plan = [$order[0]];
        for ($i = 0; $i < $scenes - 2; $i++) {
            $plan[] = $middle[$i % count($middle)];
        }
        $plan[] = end($order);

        return $plan;
    }

    public static function kind(VideoProject $project): string
    {
        $category = ProductCategory::detect($project->product);
        $key = $category?->parent?->key ?? $category?->key;

        return isset(self::PLANS[$key]) ? $key : (str_starts_with((string) $key, 'clothing') ? 'clothing' : 'other');
    }

    /** Các góc chọn được cho dự án (đổi góc từng cảnh). */
    public static function options(VideoProject $project): array
    {
        return array_map(fn (string $key) => ['value' => $key, 'label' => self::ANGLES[$key]['label']], self::PLANS[self::kind($project)]);
    }

    public static function label(?string $angle): ?string
    {
        return self::ANGLES[$angle]['label'] ?? null;
    }

    public static function isClose(?string $angle): bool
    {
        return self::ANGLES[$angle]['close'] ?? false;
    }

    /** Câu khung hình cho prompt ảnh. */
    public static function imageLine(?string $angle): string
    {
        return isset(self::ANGLES[$angle]) ? 'Camera angle for this shot: '.self::ANGLES[$angle]['image'] : '';
    }

    /** Câu hành động / máy quay cho prompt clip. */
    public static function moveLine(?string $angle): string
    {
        return isset(self::ANGLES[$angle]) ? 'Camera and action for this shot: '.self::ANGLES[$angle]['move'] : '';
    }
}
