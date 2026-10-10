<?php

namespace App\Enums;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Scene;
use App\Support\ProductFidelity;
use App\Support\Realism;

/** Kiểu chuyển động của nhân vật khi biến ảnh cảnh thành clip (muse.ai tạo, hoặc tự tạo rồi tải lên). */
enum MotionStyle: string
{
    // Quần áo.
    case TiktokDance = 'tiktok_dance';
    case HandDance = 'hand_dance';
    case OutfitSpin = 'outfit_spin';
    case Catwalk = 'catwalk';
    case Groove = 'groove';
    // Sản phẩm không phải quần áo (phụ kiện, giày, túi, đồ công nghệ, mỹ phẩm...).
    case ProductShowcase = 'product_showcase';
    case TryOn = 'try_on';
    case Unboxing = 'unboxing';
    case DemoUse = 'demo_use';
    case WalkShowcase = 'walk_showcase';

    public function label(): string
    {
        return match ($this) {
            self::TiktokDance => 'Nhảy trend TikTok',
            self::HandDance => 'Vũ đạo tay (hand dance)',
            self::OutfitSpin => 'Xoay người khoe đồ',
            self::Catwalk => 'Catwalk tạo dáng',
            self::Groove => 'Lắc lư theo nhạc',
            self::ProductShowcase => 'Cầm sản phẩm xoay khoe',
            self::TryOn => 'Đeo / đội thử rồi tạo dáng',
            self::Unboxing => 'Mở hộp (unboxing)',
            self::DemoUse => 'Dùng thử sản phẩm',
            self::WalkShowcase => 'Đi dạo khoe phụ kiện',
        };
    }

    /** Mô tả động tác bằng tiếng Anh cho công cụ tạo video. */
    public function moves(): string
    {
        return match ($this) {
            self::TiktokDance => 'does a short cheerful TikTok trend dance: light rhythmic steps side to side, arm waves, claps and a playful pose on the beat',
            self::HandDance => 'does a TikTok hand-gesture dance: quick rhythmic hand and arm moves in front of the chest and face, small head nods, feet mostly still',
            self::OutfitSpin => 'does a slow outfit-reveal spin: turns around once to show the front, side and back of the outfit, then poses facing the camera',
            self::Catwalk => 'walks toward the camera like a runway catwalk, then stops and strikes two confident model poses',
            self::Groove => 'grooves gently to the music: relaxed body sway, shoulder bounces and a natural smile',
            self::ProductShowcase => 'is already holding the product in her hand; she slowly raises it near her face, turns it to show every side to the camera, then smiles',
            self::TryOn => 'is already wearing the product; she gently adjusts it with one hand, turns her head left and right to show it from both sides, then poses with a smile',
            self::Unboxing => 'sits at a table with a closed package already in front of her; she opens it, takes the product out of the package (it comes from inside the box, never from nowhere), holds it up to the camera and smiles',
            self::DemoUse => 'is already holding the product; she uses it the way it is meant to be used, slowly and clearly, then shows it close to the camera',
            self::WalkShowcase => 'walks casually toward the camera with the product clearly visible the whole time, then stops and shows it closer',
        };
    }

    /**
     * Sản phẩm phải có sẵn từ khung đầu và ở nguyên suốt clip: tránh kiểu AI cho sản phẩm hiện ra giữa không trung,
     * xuất hiện sau người mẫu hoặc tự thêm túi, ví không có trong ảnh.
     */
    public const CONTINUITY = 'Animate only what is already in the photo: the person and the product are visible from the very first frame. '
        .'The product stays worn or held naturally the whole time; it never appears, disappears, floats in the air, duplicates or changes shape. '
        .'Do not add any new objects, bags, wallets, hats, glasses, jewelry or accessories that are not in the photo.';

    public function prompt(Scene $scene): string
    {
        // Cảnh "trước" của video biến hình: chưa có sản phẩm.
        $product = $scene->phase === ScenePhase::Before
            ? 'The person is not wearing or holding the product yet; her clothes, hair and face stay exactly as in the photo.'
            : $scene->productLine();

        return "Animate this photo into a 5-second vertical 9:16 video. {$product} The same person {$this->moves()}. "
            ."Scene context: {$scene->visual}. "
            .self::CONTINUITY.' '
            .'Keep the face, hair and outfit exactly the same. '
            .($scene->phase === ScenePhase::Before ? '' : ProductFidelity::VIDEO.' ')
            .Realism::VIDEO.' No text, no extra people. '
            .'Keep it modest, friendly and family-friendly: not sexy, not provocative, no body-focused moves or camera angles. '
            .'If any part of this request is not appropriate to create, adjust the moves or framing yourself to a modest version and create that instead of declining; never alter the product to do so.';
    }

    /**
     * Kiểu hợp với sản phẩm theo master data loại sản phẩm (đoán theo tên), thứ tự từ hợp nhất.
     *
     * @return list<self>
     */
    public static function suggestFor(Product $product): array
    {
        return ProductCategory::detect($product)?->motionStyles() ?: [self::ProductShowcase, self::Unboxing, self::DemoUse];
    }
}
