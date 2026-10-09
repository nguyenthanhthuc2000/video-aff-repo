/** Khớp với enum App\Enums\MotionStyle. */
export const motionStyles = [
    { value: 'tiktok_dance', label: 'Nhảy trend TikTok', hint: 'Bước nhảy nhẹ theo nhịp, vẫy tay, vỗ tay' },
    { value: 'hand_dance', label: 'Vũ đạo tay (hand dance)', hint: 'Động tác tay nhanh trước ngực, chân đứng yên' },
    { value: 'outfit_spin', label: 'Xoay người khoe đồ', hint: 'Xoay một vòng cho thấy trước, hông, sau' },
    { value: 'catwalk', label: 'Catwalk tạo dáng', hint: 'Bước về phía máy, dừng lại tạo dáng' },
    { value: 'groove', label: 'Lắc lư theo nhạc', hint: 'Đung đưa nhẹ nhàng, tự nhiên' },
] as const;

export type MotionStyle = (typeof motionStyles)[number]['value'];
