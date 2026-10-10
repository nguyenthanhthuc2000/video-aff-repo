<?php

namespace App\Support;

/**
 * Giữ sản phẩm y như ảnh gốc. AI tạo ảnh hay tự "sửa" sản phẩm: đổi màu, đổi hoạ tiết, thêm tay áo / nâng cổ / kéo dài váy
 * để trông kín đáo hơn, thêm lớp áo khoác. Ảnh sản phẩm là nguồn chuẩn duy nhất; chỉ được chỉnh tư thế, khung hình, bối cảnh.
 */
final class ProductFidelity
{
    public const IMAGE = 'PRODUCT FIDELITY (most important): the product photos are the only source of truth for the product. '
        .'Reproduce the product exactly as in those photos: same color and shade, same pattern and print placement, same fabric and texture, '
        .'same neckline, collar, straps, sleeves (length and shape), hem length, cut and fit, and the same buttons, zippers, pockets, seams, logos, '
        .'text and embellishments. Do not redesign, restyle, recolor, shorten, lengthen, crop or simplify it; do not add or remove any part of it, '
        .'even if the scene description says something different about the product. '
        .'Never make the product itself more covered to look modest: no added sleeves, no higher neckline, no longer hem, no lining, '
        .'no extra layer, jacket or scarf over it. To keep the image modest, change only the pose, framing, camera distance or setting.';

    public const VIDEO = 'The product must stay exactly as in the photo in every frame: same color, pattern, fabric, neckline, sleeves, length, '
        .'details and logos; do not redesign it, cover it with other clothing or change its shape. To keep the video modest, change only the moves or framing.';

    /** Quy tắc cho Claude khi viết image_prompt (kịch bản, viết lại prompt). */
    public const SCRIPT_RULE = 'image_prompt không tả lại thiết kế sản phẩm (màu, hoạ tiết, chất liệu, độ dài, cổ áo, tay áo...) và không yêu cầu sửa sản phẩm: '
        .'chỉ gọi là "the exact product from the product photos" kèm cách mặc / cầm theo mục "Cách dùng sản phẩm". '
        .'Muốn ảnh kín đáo thì đổi tư thế, góc máy, khoảng cách, bối cảnh; KHÔNG viết thêm áo khoác, khăn, lớp lót hay đổi kiểu sản phẩm.';
}
