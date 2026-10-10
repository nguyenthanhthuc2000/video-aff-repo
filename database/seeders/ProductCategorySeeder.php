<?php

namespace Database\Seeders;

use App\Models\ProductCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * Master data loại sản phẩm mặc định. Chạy lại an toàn: chỉ thêm loại chưa có (theo key),
 * không ghi đè loại người dùng đã chỉnh trong database.
 */
class ProductCategorySeeder extends Seeder
{
    private const CLOTHING_MOTION = ['tiktok_dance', 'outfit_spin', 'catwalk', 'hand_dance', 'groove'];

    /** @var list<array{key: string, name: string, keywords: list<string>, scene_settings?: list<string>, motion_styles?: list<string>, children?: list<array>}> */
    private const CATEGORIES = [
        [
            'key' => 'phone', 'name' => 'Phụ kiện điện thoại, công nghệ',
            'usage' => 'holding a phone fitted with the exact phone case from the product photos, the case clearly visible',
            'keywords' => ['ốp lưng', 'ốp điện thoại', 'ốp', 'iphone', 'samsung', 'xiaomi', 'oppo', 'cáp sạc', 'sạc dự phòng', 'củ sạc', 'tai nghe', 'airpods', 'kính cường lực', 'giá đỡ điện thoại', 'case'],
            'scene_settings' => ['bàn làm việc gọn gàng', 'quán cà phê', 'cầm trên tay khi đi dạo phố', 'trên đường đi làm', 'phòng ngủ buổi tối', 'studio nền trơn cận sản phẩm'],
            'motion_styles' => ['demo_use', 'unboxing', 'product_showcase'],
        ],
        [
            'key' => 'beauty', 'name' => 'Mỹ phẩm, làm đẹp',
            'usage' => 'holding the exact cosmetic product from the product photos in one hand, the packaging facing the camera',
            'keywords' => ['son', 'son môi', 'son kem', 'kem dưỡng', 'kem chống nắng', 'phấn', 'serum', 'mỹ phẩm', 'nước hoa', 'sữa rửa mặt', 'tẩy trang', 'mascara', 'kẻ mắt', 'mặt nạ', 'cushion', 'toner', 'xịt khoáng'],
            'scene_settings' => ['bàn trang điểm có gương đèn', 'phòng tắm sáng sủa', 'phòng ngủ buổi sáng', 'quán cà phê', 'studio nền trơn cận sản phẩm', 'đi chơi cuối tuần'],
            'motion_styles' => ['demo_use', 'product_showcase', 'unboxing'],
        ],
        [
            'key' => 'shoes', 'name' => 'Giày dép',
            'usage' => 'wearing the exact shoes from the product photos on her feet, the shoes clearly visible',
            'keywords' => ['giày', 'dép', 'sandal', 'sneaker', 'boot', 'guốc', 'cao gót'],
            'scene_settings' => ['đi dạo phố', 'công viên cuối tuần', 'trung tâm thương mại', 'cầu thang bậc gạch', 'phòng thay đồ soi gương', 'studio nền trơn cận sản phẩm'],
            'motion_styles' => ['walk_showcase', 'catwalk', 'product_showcase'],
        ],
        [
            'key' => 'bag', 'name' => 'Túi, ví, balo',
            'usage' => 'carrying the exact bag from the product photos on her shoulder or in her hand',
            'keywords' => ['túi', 'balo', 'ba lô', 'ví', 'clutch', 'tote'],
            'scene_settings' => ['đi dạo phố', 'ngồi quán cà phê', 'đi làm ở văn phòng', 'trung tâm thương mại', 'du lịch cuối tuần', 'studio nền trơn cận sản phẩm'],
            'motion_styles' => ['walk_showcase', 'product_showcase', 'unboxing'],
        ],
        [
            'key' => 'wearable', 'name' => 'Mũ, kính, trang sức, phụ kiện đeo',
            'usage' => 'wearing the exact accessory from the product photos where it is normally worn (a hat on her head, glasses on her face, jewelry on her ears, neck or wrist)',
            'keywords' => ['mũ', 'nón', 'kính', 'khuyên tai', 'bông tai', 'vòng', 'lắc tay', 'dây chuyền', 'nhẫn', 'kẹp tóc', 'cài tóc', 'buộc tóc', 'khăn', 'thắt lưng', 'đồng hồ', 'tất', 'vớ', 'găng tay'],
            'scene_settings' => ['đi dạo phố', 'ngồi quán cà phê', 'phòng thay đồ soi gương', 'công viên cuối tuần', 'du lịch biển', 'studio nền trơn cận sản phẩm'],
            'motion_styles' => ['try_on', 'product_showcase', 'groove'],
        ],
        [
            'key' => 'clothing', 'name' => 'Quần áo',
            'usage' => 'wearing the exact garment from the product photos',
            'keywords' => ['áo', 'quần', 'váy', 'đầm', 'set', 'bộ đồ', 'đồ ngủ', 'pijama', 'yếm', 'jumpsuit', 'blazer', 'vest', 'hoodie', 'cardigan', 'legging', 'bikini', 'đồ bơi', 'corset', 'chân váy'],
            'scene_settings' => ['đi dạo phố', 'ngồi quán cà phê', 'công viên hoa cuối tuần', 'hẹn hò buổi tối ở nhà hàng', 'phòng thay đồ soi gương', 'trung tâm thương mại', 'đi làm ở văn phòng'],
            'motion_styles' => self::CLOTHING_MOTION,
            // Loại phụ: xét theo thứ tự, loại nào có từ khoá trong tên thì dùng bối cảnh của loại đó.
            'children' => [
                [
                    'key' => 'clothing_party', 'name' => 'Đầm dự tiệc, dạ hội',
                    'keywords' => ['dự tiệc', 'dạ hội', 'đầm tiệc', 'váy tiệc', 'váy cưới', 'evening', 'party', 'sequin', 'kim sa', 'corset', 'sang trọng', 'cúp ngực'],
                    'scene_settings' => ['tiệc tối ở nhà hàng sang trọng', 'sảnh khách sạn lung linh đèn', 'phòng thay đồ soi gương trước khi đi tiệc', 'cầu thang xoắn sang trọng', 'ban công nhìn ra thành phố về đêm', 'studio nền trơn khoe dáng váy'],
                ],
                [
                    'key' => 'clothing_office', 'name' => 'Đồ công sở',
                    'keywords' => ['công sở', 'văn phòng', 'vest', 'blazer', 'sơ mi', 'quần tây', 'chân váy bút chì', 'office'],
                    'scene_settings' => ['văn phòng sáng sủa', 'quán cà phê buổi sáng trước giờ làm', 'đường phố trên đường đi làm', 'sảnh và thang máy toà nhà', 'phòng họp kính', 'nhà hàng ăn trưa cùng đồng nghiệp'],
                ],
                [
                    'key' => 'clothing_sleep', 'name' => 'Đồ ngủ, đồ mặc nhà',
                    'keywords' => ['đồ ngủ', 'pijama', 'pyjama', 'mặc nhà', 'đồ bộ', 'homewear'],
                    'scene_settings' => ['phòng ngủ buổi sáng nắng nhẹ', 'phòng khách thư giãn đọc sách', 'bếp pha trà buổi sáng', 'ban công tưới cây', 'sofa xem phim cuối tuần', 'góc cửa sổ uống cà phê'],
                ],
                [
                    'key' => 'clothing_swim', 'name' => 'Đồ bơi, đồ đi biển',
                    'keywords' => ['đồ bơi', 'bikini', 'đi biển', 'beach', 'swim'],
                    'scene_settings' => ['bãi biển buổi sáng', 'hồ bơi resort', 'quán cà phê ven biển', 'cầu gỗ trên biển lúc hoàng hôn', 'sảnh resort nhiệt đới', 'phố biển đi dạo buổi chiều'],
                ],
                [
                    'key' => 'clothing_sport', 'name' => 'Đồ thể thao',
                    'keywords' => ['thể thao', 'gym', 'yoga', 'legging', 'jogger', 'running', 'sport'],
                    'scene_settings' => ['phòng gym hiện đại', 'công viên chạy bộ buổi sáng', 'phòng tập yoga ánh sáng tự nhiên', 'sân tennis', 'đường chạy ven hồ', 'quán nước ép sau buổi tập'],
                ],
                [
                    'key' => 'clothing_outer', 'name' => 'Áo khoác, đồ len, mùa lạnh',
                    'keywords' => ['áo khoác', 'len', 'cardigan', 'hoodie', 'măng tô', 'áo phao', 'mùa đông', 'sweater', 'coat'],
                    'scene_settings' => ['phố mùa thu lá vàng', 'quán cà phê ấm áp ngày se lạnh', 'hiệu sách nhỏ', 'ga tàu buổi sớm', 'chợ đêm đèn vàng', 'phòng khách ấm cúng'],
                ],
                [
                    'key' => 'clothing_street', 'name' => 'Áo thun, jean, streetwear',
                    'keywords' => ['áo thun', 'áo phông', 'quần jean', 'jeans', 'streetwear', 'cargo', 'tee', 'baggy'],
                    'scene_settings' => ['phố đi bộ năng động', 'trung tâm thương mại', 'quán trà sữa cùng bạn bè', 'tường graffiti', 'công viên cuối tuần', 'tiệm băng đĩa / hiệu sách'],
                ],
            ],
        ],
        [
            'key' => 'other', 'name' => 'Khác',
            'usage' => 'holding or using the exact product from the product photos, the product clearly visible',
            'keywords' => [],
            'scene_settings' => ['góc bàn ở nhà gọn gàng', 'ngồi quán cà phê', 'đi làm ở văn phòng', 'công viên cuối tuần', 'trung tâm thương mại', 'studio nền trơn cận sản phẩm'],
            'motion_styles' => ['product_showcase', 'unboxing', 'demo_use'],
        ],
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $order => $category) {
            $parent = $this->insert($category, null, $order);
            foreach ($category['children'] ?? [] as $childOrder => $child) {
                $this->insert($child, $parent->id, $childOrder);
            }
        }
    }

    private function insert(array $data, ?int $parentId, int $order): ProductCategory
    {
        // Migration tạo bảng chạy seeder trước khi có cột "usage" (thêm ở migration sau).
        $values = [
            'parent_id' => $parentId,
            'name' => $data['name'],
            'keywords' => $data['keywords'],
            'scene_settings' => $data['scene_settings'] ?? null,
            'motion_styles' => $data['motion_styles'] ?? null,
            'usage' => $data['usage'] ?? null,
            'sort_order' => $order,
        ];
        if (! Schema::hasColumn('product_categories', 'usage')) {
            unset($values['usage']);
        }

        return ProductCategory::firstOrCreate(['key' => $data['key']], $values);
    }
}
