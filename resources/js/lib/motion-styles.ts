/** Khớp với enum App\Enums\MotionStyle. */
export const motionStyles = [
    { value: 'tiktok_dance', label: 'Nhảy trend TikTok', hint: 'Bước nhảy nhẹ theo nhịp, vẫy tay, vỗ tay' },
    { value: 'hand_dance', label: 'Vũ đạo tay (hand dance)', hint: 'Động tác tay nhanh trước ngực, chân đứng yên' },
    { value: 'outfit_spin', label: 'Xoay người khoe đồ', hint: 'Xoay một vòng cho thấy trước, hông, sau' },
    { value: 'catwalk', label: 'Catwalk tạo dáng', hint: 'Bước về phía máy, dừng lại tạo dáng' },
    { value: 'groove', label: 'Lắc lư theo nhạc', hint: 'Đung đưa nhẹ nhàng, tự nhiên' },
    { value: 'product_showcase', label: 'Cầm sản phẩm xoay khoe', hint: 'Đang cầm sẵn, đưa lên gần mặt, xoay cho thấy mọi phía' },
    { value: 'try_on', label: 'Đeo / đội thử rồi tạo dáng', hint: 'Mũ, kính, trang sức: đang đeo sẵn, chỉnh nhẹ, quay trái phải' },
    { value: 'unboxing', label: 'Mở hộp (unboxing)', hint: 'Mở gói hàng, lấy sản phẩm ra, cười thích thú' },
    { value: 'demo_use', label: 'Dùng thử sản phẩm', hint: 'Đang cầm sẵn, dùng đúng cách rồi khoe gần máy' },
    { value: 'walk_showcase', label: 'Đi dạo khoe phụ kiện', hint: 'Giày, túi, đồng hồ: đi về phía máy rồi khoe gần' },
] as const;

export type MotionStyle = (typeof motionStyles)[number]['value'];
