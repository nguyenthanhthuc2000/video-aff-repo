<?php

namespace App\Services\Script;

use App\Models\Product;

/** Nội dung prompt viết kịch bản, dùng chung cho Claude API và Claude bản web. */
class ScriptPrompt
{
    public const SYSTEM = <<<'PROMPT'
    Bạn là người viết kịch bản video affiliate ngắn (Shopee Video, dọc 9:16, 20–30 giây) cho sản phẩm thời trang.

    Viết bằng tiếng Việt tự nhiên, giọng người thật đang giới thiệu đồ mình thích, câu ngắn, dễ đọc thành lời.

    Đầu ra:
    - hooks: đúng 3 câu mở đầu khác nhau (mỗi câu dưới 12 từ), gây tò mò trong 2 giây đầu.
    - scenes: 5–7 cảnh, tổng thời lượng 20–30 giây. Mỗi cảnh có:
      - visual: mô tả khung hình bằng tiếng Việt.
      - on_screen_text: chữ trên màn hình, tối đa 8 từ.
      - voiceover: lời đọc cho cảnh, khớp thời lượng (khoảng 3 từ mỗi giây).
      - image_prompt: prompt tiếng Anh để AI chỉnh ảnh người mẫu mặc đúng sản phẩm trong ảnh tham chiếu. Mở đầu bằng bối cảnh cụ thể (địa điểm, đồ vật xung quanh, thời điểm trong ngày), rồi đến tư thế, hành động, góc máy, ánh sáng. Giữ nguyên màu, hoạ tiết, kiểu dáng sản phẩm; khung dọc 9:16.
      - duration_ms: 2000–6000.
    - Mỗi cảnh một bối cảnh khác hẳn nhau, không lặp địa điểm, như một ngày mặc sản phẩm. Chọn bối cảnh hợp với sản phẩm, ví dụ:
      phòng thay đồ / soi gương thử đồ, đi dạo phố, ngồi quán cà phê, đi làm ở văn phòng, trung tâm thương mại, công viên cuối tuần,
      hẹn hò buổi tối ở nhà hàng, du lịch biển, ở nhà buổi sáng, cận cảnh chất vải và đường may trên nền trơn.
    - Đổi cả tư thế và góc máy giữa các cảnh (toàn thân đang bước đi, ngồi, xoay người, nửa người, cận chi tiết); không lặp cùng một kiểu đứng tạo dáng.
    - Phong cách trang nhã, lịch sự như ảnh thời trang thương mại: KHÔNG gợi cảm, không sexy, không khiêu khích.
      Không mô tả hay nhấn vào cơ thể (ngực, eo, đùi, khe xẻ, phần hở, độ mỏng xuyên thấu), không góc máy thấp nhìn lên hay cận cơ thể.
      Với đồ ren, đồ mỏng, đồ ôm, đồ ngủ: tư thế kín đáo tự nhiên, góc máy ngang tầm mắt, cận chi tiết thì chụp vải trên móc/manơcanh hoặc trải phẳng.
    - Ánh sáng và thời điểm cũng nên khác nhau (nắng sáng, chiều vàng, đèn quán ấm, đèn văn phòng, đèn tối).
    - Cảnh cuối là kêu gọi mua: "xem màu, size, ưu đãi trong giỏ hàng".
    - caption: 1–2 câu cho bài đăng.
    - hashtags: 3–6 hashtag, có dấu #.

    Quy tắc:
    - Chỉ nói những gì có trong thông tin sản phẩm, ảnh và đánh giá. Không bịa chất liệu ("lụa cao cấp"), công dụng ("không lộ", "che bụng"), số liệu bán hàng hay giá cố định, giảm giá.
    - Không so sánh hạ thấp thương hiệu khác. Không dùng từ tuyệt đối ("nhất", "100%").
    - Nếu đánh giá có điểm chê (ví dụ form nhỏ), có thể khéo léo khuyên chọn size phù hợp, không giấu.
    PROMPT;

    /** Thông tin sản phẩm gửi kèm (phần tin nhắn người dùng). */
    public static function productInfo(Product $product): string
    {
        $details = array_filter($product->details ?? []);

        $lines = ["Sản phẩm: {$product->name}"];
        if ($product->price) {
            $lines[] = 'Giá tham khảo (không ghi vào kịch bản): '.number_format($product->price, 0, ',', '.').'đ';
        }
        $labels = ['sizes' => 'Size', 'material' => 'Chất liệu', 'colors' => 'Màu', 'notes' => 'Ghi chú'];
        foreach ($labels as $key => $label) {
            if (! empty($details[$key])) {
                $lines[] = "{$label}: {$details[$key]}";
            }
        }
        if ($product->reviews) {
            $lines[] = "Đánh giá của người mua:\n{$product->reviews}";
        }

        return implode("\n", $lines);
    }

    /** Prompt đầy đủ để dán vào claude.ai (không có structured outputs nên yêu cầu JSON theo mẫu). */
    public static function forWeb(Product $product): string
    {
        $example = json_encode([
            'hooks' => ['...', '...', '...'],
            'scenes' => [[
                'visual' => '...',
                'on_screen_text' => '...',
                'voiceover' => '...',
                'image_prompt' => '...',
                'duration_ms' => 3000,
            ]],
            'caption' => '...',
            'hashtags' => ['#...'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return self::SYSTEM."\n\n"
            .self::productInfo($product)."\n\n"
            ."Ảnh đính kèm là ảnh sản phẩm.\n\n"
            ."Chỉ trả lời bằng một khối JSON đúng cấu trúc sau, không thêm chữ nào khác:\n"
            .$example;
    }

    /**
     * Đọc JSON từ câu trả lời dán về (chấp nhận khối ```json và chữ thừa xung quanh).
     *
     * @throws ScriptWriterException
     */
    public static function parseWebReply(string $reply): array
    {
        $start = strpos($reply, '{');
        $end = strrpos($reply, '}');
        if ($start === false || $end === false || $end < $start) {
            throw new ScriptWriterException('Không tìm thấy JSON trong câu trả lời của Claude.');
        }

        $data = json_decode(substr($reply, $start, $end - $start + 1), true);
        if (! is_array($data)) {
            throw new ScriptWriterException('JSON trong câu trả lời bị lỗi: '.json_last_error_msg().'.');
        }

        return ScriptSchema::normalize($data);
    }
}
