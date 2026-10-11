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
     * label: tên hiển thị; image: khung hình khi tạo ảnh; move: hành động / máy quay khi tạo clip; close: góc khoe chi tiết
     * (chỉ làm hành động khoe, không nhảy theo kiểu chuyển động). Mọi góc đều quay toàn thân, máy đặt xa (xem Framing): chi tiết khoe bằng tay chỉ / chạm, không cận.
     *
     * @var array<string, array{label: string, image: string, move: string, close: bool}>
     */
    public const ANGLES = [
        // Quần áo.
        'front_full' => [
            'label' => 'Toàn thân chính diện',
            'image' => 'Wide full-body shot from the front at eye level, the whole garment visible from neckline to hem.',
            'move' => 'She faces the camera and shifts her weight naturally, showing the whole front of the garment.',
            'close' => false,
        ],
        'top_detail' => [
            'label' => 'Toàn thân, chạm cổ áo / phần trên',
            'image' => 'Wide full-body shot from the front; she lightly touches the neckline and upper details of the garment with her fingers to point them out.',
            'move' => 'Full body in frame the whole time; she lightly touches the neckline and upper details with her fingers to point them out.',
            'close' => true,
        ],
        'fabric' => [
            'label' => 'Toàn thân, vuốt chất vải',
            'image' => 'Wide full-body shot; one hand smooths or lightly pinches the fabric at her side so the material moves and its texture shows, her whole body still in frame.',
            'move' => 'Full body in frame the whole time; she smooths and lightly pinches the fabric at her side so it moves and shows how soft it is.',
            'close' => true,
        ],
        'side' => [
            'label' => 'Toàn thân góc nghiêng',
            'image' => 'Wide full-body three-quarter side view, showing the silhouette and how the garment fits from the side.',
            'move' => 'She turns slowly to the side and back so the silhouette and fit are visible from the side, full body in frame.',
            'close' => false,
        ],
        'back' => [
            'label' => 'Toàn thân mặt sau',
            'image' => 'Wide full-body view from behind as she glances back over her shoulder, showing the back of the garment. If the product photos do not show the back, keep the back plain and consistent with the front (same color, fabric and pattern); do not invent details.',
            'move' => 'She turns around to show the back of the garment, glances over her shoulder, then turns back toward the camera, full body in frame.',
            'close' => false,
        ],
        'bottom_detail' => [
            'label' => 'Toàn thân, khoe tay áo / eo / gấu',
            'image' => 'Wide full-body shot; her hands show the sleeves, waist or hem of the garment (lifting the hem slightly or smoothing the waist), her whole body in frame.',
            'move' => 'Full body in frame the whole time; her hands show the sleeves, waist and hem, lifting the hem slightly and letting it fall.',
            'close' => true,
        ],
        'motion' => [
            'label' => 'Đi lại cho thấy độ rủ',
            'image' => 'Wide full-body candid shot while she walks toward the camera from a distance, the fabric moving naturally.',
            'move' => 'She walks a few steps toward the camera and does a small turn so the fabric moves and flows naturally; she stops while her full body is still in frame.',
            'close' => false,
        ],
        'styling' => [
            'label' => 'Phối đồ trước gương',
            'image' => 'Full-body mirror selfie holding a phone, the whole reflection visible from head to feet, showing the complete outfit with the product.',
            'move' => 'She poses casually in front of the mirror, adjusts the outfit and checks herself from both sides, full body in the mirror.',
            'close' => false,
        ],
        // Giày dép.
        'shoes_on' => [
            'label' => 'Toàn thân, khoe giày',
            'image' => 'Wide full-body shot from the front, the shoes clearly visible on her feet.',
            'move' => 'Full body in frame; she shifts her weight and turns one foot to show the shoes from the front and side.',
            'close' => true,
        ],
        'shoes_side' => [
            'label' => 'Toàn thân, nghiêng khoe dáng giày',
            'image' => 'Wide full-body side view, the shoe profile, heel and sole visible.',
            'move' => 'Full body in frame; she lifts her heel slightly and turns so the side of the shoes is visible.',
            'close' => true,
        ],
        'shoes_hand' => [
            'label' => 'Toàn thân, cầm giày khoe đế',
            'image' => 'Wide full-body shot; she holds one shoe up at chest height toward the camera, showing the sole and material.',
            'move' => 'Full body in frame; she holds one shoe up toward the camera and turns it to show the sole and the material.',
            'close' => true,
        ],
        'shoes_walk' => [
            'label' => 'Đi thử',
            'image' => 'Wide full-body shot while she walks toward the camera, the shoes clearly visible.',
            'move' => 'She walks a few natural steps toward the camera, full body in frame.',
            'close' => false,
        ],
        // Túi.
        'bag_carry' => [
            'label' => 'Đeo toàn thân',
            'image' => 'Wide full-body shot carrying the bag as intended, the whole bag clearly visible.',
            'move' => 'She walks a few steps carrying the bag, then stops and adjusts the strap, full body in frame.',
            'close' => false,
        ],
        'bag_front' => [
            'label' => 'Toàn thân, đưa mặt trước túi',
            'image' => 'Wide full-body shot; she holds the bag out in front of her toward the camera, logo and hardware visible.',
            'move' => 'Full body in frame; she holds the bag out toward the camera and slowly turns it to show the front and the hardware.',
            'close' => true,
        ],
        'bag_inside' => [
            'label' => 'Toàn thân, mở túi',
            'image' => 'Wide full-body shot; she opens the bag in front of her to show the inside. Keep the inside simple if the photos do not show it.',
            'move' => 'Full body in frame; she opens the bag, shows the inside to the camera and closes it again.',
            'close' => true,
        ],
        'bag_side' => [
            'label' => 'Toàn thân, xoay túi',
            'image' => 'Wide full-body shot; she turns the bag to show its side, back and strap.',
            'move' => 'Full body in frame; she turns the bag around to show its side, back and strap.',
            'close' => true,
        ],
        // Phụ kiện đeo.
        'worn_close' => [
            'label' => 'Toàn thân khi đeo',
            'image' => 'Wide full-body shot wearing the accessory, which stays clearly visible.',
            'move' => 'Full body in frame; she turns slightly so the light catches the accessory and it is visible from both sides.',
            'close' => true,
        ],
        'held_detail' => [
            'label' => 'Toàn thân, cầm khoe chi tiết',
            'image' => 'Wide full-body shot; she holds the product out toward the camera at chest height, its details visible.',
            'move' => 'Full body in frame; she holds the product out toward the camera and turns it slowly to show every side.',
            'close' => true,
        ],
        'worn_full' => [
            'label' => 'Tổng thể khi đeo / dùng',
            'image' => 'Wide full-body shot wearing or using the product with a matching outfit.',
            'move' => 'She moves naturally while wearing or using the product, full body in frame.',
            'close' => false,
        ],
        // Mỹ phẩm, đồ công nghệ, khác.
        'held_front' => [
            'label' => 'Toàn thân, cầm giới thiệu',
            'image' => 'Wide full-body shot; she holds the product up next to her face toward the camera, label facing the camera.',
            'move' => 'Full body in frame; she raises the product next to her face, label to the camera, and smiles.',
            'close' => false,
        ],
        'label_close' => [
            'label' => 'Toàn thân, đưa bao bì',
            'image' => 'Wide full-body shot; she holds the product out toward the camera, packaging and label facing the camera.',
            'move' => 'Full body in frame; she holds the product out and turns it slowly to show the label and every side.',
            'close' => true,
        ],
        'texture' => [
            'label' => 'Toàn thân, thử sản phẩm',
            'image' => 'Wide full-body shot; she opens the product and tries a little of it on the back of her hand.',
            'move' => 'Full body in frame; she opens the product and swatches a little of it on the back of her hand.',
            'close' => true,
        ],
        'in_use' => [
            'label' => 'Đang dùng',
            'image' => 'Wide full-body shot; she is using the product the way it is meant to be used, the product clearly visible.',
            'move' => 'She uses the product the way it is meant to be used, slowly and clearly, full body in frame.',
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
