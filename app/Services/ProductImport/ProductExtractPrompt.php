<?php

namespace App\Services\ProductImport;

/** Prompt rút thông tin sản phẩm từ trang Shopee, dùng chung cho Claude API và Claude bản web. */
class ProductExtractPrompt
{
    public const SYSTEM = <<<'PROMPT'
    Bạn đọc nội dung chữ của một trang sản phẩm thời trang trên Shopee hoặc TikTok Shop (copy từ trình duyệt, có lẫn menu, gợi ý sản phẩm khác, chân trang) và rút ra thông tin để làm video affiliate.

    Đầu ra:
    - name: tên sản phẩm, bỏ phần quảng cáo thừa (ví dụ "[FREESHIP]", "HOT", emoji), giữ tiếng Việt có dấu.
    - price: giá bán hiện tại bằng đồng (số nguyên, không dấu chấm). Có khoảng giá thì lấy giá thấp nhất. Không thấy thì null.
    - sizes: các size có bán, ngắn gọn (ví dụ "S, M, L, XL" hoặc "XS–XL"), kèm bảng size nếu có (ví dụ "M: 45–50kg").
    - material: chất liệu như shop ghi.
    - colors: các màu/phân loại có bán, cách nhau bằng dấu phẩy.
    - notes: đặc điểm kiểu dáng, form, chi tiết đáng nói trong mô tả (tối đa 5 ý, mỗi ý một dòng, bắt đầu bằng "- ").
    - reviews: tối đa 6 đánh giá nổi bật của người mua, có cả khen lẫn chê nếu có, mỗi đánh giá một dòng, bắt đầu bằng "- ". Giữ ý người viết, có thể rút gọn.

    Quy tắc:
    - Chỉ lấy thông tin của đúng sản phẩm chính trên trang, bỏ qua sản phẩm gợi ý, quảng cáo, thông tin shop.
    - Không bịa. Trường nào không có trong trang thì để chuỗi rỗng (price thì null).
    PROMPT;

    public static function userText(PageSnapshot $page): string
    {
        return "Link: {$page->url}\nTiêu đề tab: {$page->title}\n\nNội dung trang:\n{$page->text}";
    }

    /** Prompt đầy đủ để dán vào claude.ai (không có structured outputs nên yêu cầu JSON theo mẫu). */
    public static function forWeb(PageSnapshot $page): string
    {
        $example = json_encode([
            'name' => '...',
            'price' => 259000,
            'sizes' => '...',
            'material' => '...',
            'colors' => '...',
            'notes' => '- ...',
            'reviews' => '- ...',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return self::SYSTEM."\n\n"
            .self::userText($page)."\n\n"
            ."Chỉ trả lời bằng một khối JSON đúng cấu trúc sau, không thêm chữ nào khác:\n"
            .$example;
    }

    public static function schema(): array
    {
        $string = ['type' => 'string'];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['name', 'price', 'sizes', 'material', 'colors', 'notes', 'reviews'],
            'properties' => [
                'name' => $string,
                'price' => ['type' => ['integer', 'null']],
                'sizes' => $string,
                'material' => $string,
                'colors' => $string,
                'notes' => $string,
                'reviews' => $string,
            ],
        ];
    }

    /**
     * Chuẩn hoá về độ dài khớp với validate của form sản phẩm.
     *
     * @throws ProductImportException
     */
    public static function normalize(mixed $data): array
    {
        if (! is_array($data)) {
            throw new ProductImportException('Claude không trả về thông tin sản phẩm.');
        }

        $text = fn (string $key, int $max) => mb_substr(trim((string) ($data[$key] ?? '')), 0, $max);
        $price = $data['price'] ?? null;
        if (is_string($price)) {
            $price = preg_replace('/\D/', '', $price);
        }

        return [
            'name' => $text('name', 255),
            'price' => is_numeric($price) && (int) $price > 0 ? (int) $price : null,
            'sizes' => $text('sizes', 255),
            'material' => $text('material', 255),
            'colors' => $text('colors', 255),
            'notes' => $text('notes', 2000),
            'reviews' => $text('reviews', 5000),
        ];
    }

    /**
     * Đọc JSON từ câu trả lời dán về (chấp nhận khối ```json và chữ thừa xung quanh).
     *
     * @throws ProductImportException
     */
    public static function parseWebReply(string $reply): array
    {
        $start = strpos($reply, '{');
        $end = strrpos($reply, '}');
        if ($start === false || $end === false || $end < $start) {
            throw new ProductImportException('Không tìm thấy JSON trong câu trả lời của Claude.');
        }

        $data = json_decode(substr($reply, $start, $end - $start + 1), true);
        if (! is_array($data)) {
            throw new ProductImportException('JSON trong câu trả lời bị lỗi: '.json_last_error_msg().'.');
        }

        return self::normalize($data);
    }
}
