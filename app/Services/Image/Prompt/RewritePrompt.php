<?php

namespace App\Services\Image\Prompt;

use App\Models\Scene;

/** Nội dung yêu cầu Claude viết lại prompt, dùng chung cho Claude Code CLI và Claude API. */
final class RewritePrompt
{
    public const SYSTEM = <<<'PROMPT'
    Bạn viết lại prompt tạo ảnh thời trang (tiếng Anh) cho một cảnh video bán hàng. Lý do viết lại được ghi kèm:
    công cụ tạo ảnh đã từ chối prompt cũ (thường vì ảnh trông gợi cảm), hoặc người dùng muốn một ảnh khác cho cảnh này.
    - Giữ bối cảnh (địa điểm, đồ vật xung quanh, thời điểm, ánh sáng). Sản phẩm phải giữ y như ảnh gốc.
    - image_prompt không tả lại thiết kế sản phẩm (màu, hoạ tiết, chất liệu, độ dài, cổ áo, tay áo...) và không yêu cầu sửa sản phẩm: chỉ gọi là "the exact product from the product photos" kèm cách mặc / cầm theo mục "Cách dùng sản phẩm". Muốn ảnh kín đáo thì đổi tư thế, góc máy, khoảng cách, bối cảnh; KHÔNG viết thêm áo khoác, khăn, lớp lót hay đổi kiểu sản phẩm.
    - Prompt mới phải khác prompt cũ: đổi tư thế, hành động, góc máy hoặc bố cục.
    - Phong cách ảnh đời thường người mua tự chụp bằng điện thoại, trang nhã: tư thế tự nhiên kín đáo (đứng, đi, ngồi thẳng), góc máy ngang tầm mắt, toàn thân hoặc 3/4, ánh sáng có sẵn; không dùng các từ cinematic, studio, professional photoshoot, perfect, flawless, 8k, dreamy, glamour.
    - Bỏ mọi chi tiết gợi cảm: không nhắc cơ thể (ngực, eo, hông, đùi, chân trần), khe xẻ, phần hở, độ mỏng/xuyên thấu, không dùng từ sexy, seductive, sensual, alluring; không góc máy thấp nhìn lên, không cận cơ thể.
    - Cảnh cận chi tiết (ren, vải, đường may): chuyển thành ảnh sản phẩm trên manơcanh, móc treo hoặc trải phẳng, không có người.
    - Cảnh "trước" của video biến hình (mô tả có ảnh "trước", đồ cũ, mặt mộc): không tả hay đổi quần áo, tóc, mặt; chỉ tả bối cảnh, tư thế, biểu cảm.
    - Chỉ đúng một sản phẩm đang bán, luôn mặc / đeo / cầm trên người mẫu; không thêm túi, ví, mũ, kính, trang sức hay phụ kiện khác.
    - Ghi rõ khung dọc 9:16. Dưới 80 từ. Chỉ trả prompt mới.
    PROMPT;

    /** Lý do khi người dùng chỉ muốn ảnh khác (không bị từ chối). */
    public const VARIATION = 'Người dùng muốn một ảnh khác cho cảnh này.';

    public static function userText(Scene $scene, string $reason): string
    {
        $why = $reason === self::VARIATION ? $reason : "Công cụ tạo ảnh đã từ chối prompt cũ và trả lời:\n{$reason}";

        return "Mô tả cảnh: {$scene->visual}\n\nPrompt cũ:\n{$scene->image_prompt}\n\nLý do viết lại: {$why}";
    }

    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['image_prompt' => ['type' => 'string']],
            'required' => ['image_prompt'],
            'additionalProperties' => false,
        ];
    }

    /** Kiểm tra kết quả Claude; rỗng hoặc quá ngắn thì coi như thất bại. */
    public static function clean(mixed $prompt): string
    {
        $prompt = trim((string) $prompt);
        if (mb_strlen($prompt) < 20) {
            throw new \RuntimeException('Claude không trả prompt mới.');
        }

        return mb_substr($prompt, 0, 2000);
    }
}
