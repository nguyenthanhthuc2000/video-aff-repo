<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Str;

/** Bối cảnh quay gợi ý theo loại sản phẩm (đoán từ tên), để các cảnh hợp với cách người mua sẽ mặc. */
final class SceneSettings
{
    /** Từ khoá trong tên (không dấu) => bối cảnh. Nhóm cụ thể đứng trước nhóm chung. */
    private const GROUPS = [
        'du tiec|da hoi|dam tiec|vay tiec|vay cuoi|evening|party|sequin|kim sa|corset|sang trong|cup nguc|dam lua' => [
            'tiệc tối ở nhà hàng sang trọng', 'sảnh khách sạn lung linh đèn', 'phòng thay đồ soi gương trước khi đi tiệc',
            'cầu thang xoắn sang trọng', 'ban công nhìn ra thành phố về đêm', 'studio nền trơn khoe dáng váy',
        ],
        'cong so|van phong|vest|blazer|so mi|quan tay|chan vay but chi|office' => [
            'văn phòng sáng sủa', 'quán cà phê buổi sáng trước giờ làm', 'đường phố trên đường đi làm',
            'sảnh và thang máy toà nhà', 'phòng họp kính', 'nhà hàng ăn trưa cùng đồng nghiệp',
        ],
        'do ngu|pijama|pyjama|do mac nha|do bo mac nha|homewear|sleep' => [
            'phòng ngủ buổi sáng nắng nhẹ', 'phòng khách thư giãn đọc sách', 'bếp pha trà buổi sáng',
            'ban công tưới cây', 'sofa xem phim cuối tuần', 'góc cửa sổ uống cà phê',
        ],
        'do boi|bikini|di bien|maxi bien|beach|swim' => [
            'bãi biển buổi sáng', 'hồ bơi resort', 'quán cà phê ven biển', 'cầu gỗ trên biển lúc hoàng hôn',
            'sảnh resort nhiệt đới', 'phố biển đi dạo buổi chiều',
        ],
        'the thao|gym|yoga|legging|jogger|running|sport' => [
            'phòng gym hiện đại', 'công viên chạy bộ buổi sáng', 'phòng tập yoga ánh sáng tự nhiên',
            'sân tennis', 'đường chạy ven hồ', 'quán nước ép sau buổi tập',
        ],
        'ao khoac|len|cardigan|hoodie|mang to|ao phao|mua dong|sweater|coat' => [
            'phố mùa thu lá vàng', 'quán cà phê ấm áp ngày se lạnh', 'hiệu sách nhỏ',
            'ga tàu buổi sớm', 'chợ đêm đèn vàng', 'phòng khách ấm cúng',
        ],
        'ao thun|quan jean|jeans|streetwear|ao phong|hoodie|cargo|tee' => [
            'phố đi bộ năng động', 'trung tâm thương mại', 'quán trà sữa cùng bạn bè',
            'tường graffiti', 'công viên cuối tuần', 'tiệm băng đĩa / hiệu sách',
        ],
        'vay|dam|chan vay|yem|maxi' => [
            'đi dạo phố', 'ngồi quán cà phê', 'công viên hoa cuối tuần', 'hẹn hò buổi tối ở nhà hàng',
            'phòng thay đồ soi gương', 'trung tâm thương mại', 'đi làm ở văn phòng',
        ],
    ];

    private const DEFAULT = [
        'phòng thay đồ soi gương', 'đi dạo phố', 'ngồi quán cà phê', 'đi làm ở văn phòng',
        'trung tâm thương mại', 'công viên cuối tuần', 'studio nền trơn cận chất liệu',
    ];

    /** @return list<string> */
    public static function suggestFor(Product $product): array
    {
        $name = ' '.Str::of($product->name)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ').' ';

        foreach (self::GROUPS as $keywords => $settings) {
            foreach (explode('|', $keywords) as $keyword) {
                if (str_contains($name, " {$keyword} ")) {
                    return $settings;
                }
            }
        }

        return self::DEFAULT;
    }
}
