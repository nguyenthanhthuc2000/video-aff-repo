/**
 * Gói nạp Credits: 1 Credit = 1đ (nạp 10.000đ được 10.000 Credits); gói lớn được tặng thêm (tối đa 10%).
 * Giá từng bước do admin đặt (Cài đặt hệ thống → Giá bán Credits), máy chủ gửi xuống qua prop `creditRates`.
 */

export const VND_PER_CREDIT = 1;

export interface TopUpPackage {
    key: string;
    name: string;
    price: number;
    credits: number;
    bonusPercent: number;
    popular?: boolean;
    hint: string;
}

export const packages: TopUpPackage[] = [
    { key: 'trial', name: 'Dùng thử', price: 50_000, credits: 50_000, bonusPercent: 0, hint: 'Đủ làm vài video thường để làm quen.' },
    { key: 'basic', name: 'Cơ bản', price: 100_000, credits: 102_000, bonusPercent: 2, hint: 'Cho người mới, vài sản phẩm mỗi tuần.' },
    { key: 'popular', name: 'Phổ biến', price: 200_000, credits: 210_000, bonusPercent: 5, popular: true, hint: 'Lựa chọn của đa số dân affiliate.' },
    { key: 'pro', name: 'Chuyên nghiệp', price: 500_000, credits: 540_000, bonusPercent: 8, hint: 'Ra video mỗi ngày, nhiều sản phẩm song song.' },
    { key: 'business', name: 'Doanh nghiệp', price: 1_000_000, credits: 1_100_000, bonusPercent: 10, hint: 'Cho team, shop lớn, chạy nhiều kênh.' },
];

/** Giá (Credits) từng bước, theo bảng giá bán của admin. Bước AI viết chữ là trung bình thực tế. */
export interface CreditRates {
    image: number;
    clip_style: number;
    clip_copy: number;
    render: number;
    render_long: number;
    script: number;
    product_import: number;
    motion_analysis: number;
    scene_prompt_rewrite: number;
}

export type MotionChoice = 'none' | 'style' | 'copy';

/** Ước lượng Credits cho một video (máy tính trên trang chủ và trang Nạp tiền). */
export function estimateCredits(
    rates: CreditRates,
    { scenes, motion, seconds }: { scenes: number; motion: MotionChoice; transformation?: boolean; seconds: number },
) {
    const items = [
        { label: 'Đọc thông tin sản phẩm', credits: rates.product_import },
        { label: 'Viết kịch bản', credits: rates.script },
        { label: `${scenes} ảnh cảnh × ${formatNumber(rates.image)}`, credits: scenes * rates.image },
        ...(motion === 'style'
            ? [{ label: `${scenes} clip chuyển động × ${formatNumber(rates.clip_style)}`, credits: scenes * rates.clip_style }]
            : []),
        ...(motion === 'copy'
            ? [
                  { label: 'Phân tích video mẫu', credits: rates.motion_analysis },
                  { label: `${scenes} clip sao chép chuyển động × ${formatNumber(rates.clip_copy)}`, credits: scenes * rates.clip_copy },
              ]
            : []),
        { label: seconds > 30 ? 'Xuất video (trên 30 giây)' : 'Xuất video', credits: seconds > 30 ? rates.render_long : rates.render },
    ];
    const total = items.reduce((sum, item) => sum + item.credits, 0);

    return { items, total, vnd: total * VND_PER_CREDIT };
}

export const formatNumber = (value: number) => value.toLocaleString('vi-VN');
export const formatVndShort = (value: number) => `${value.toLocaleString('vi-VN')}đ`;
