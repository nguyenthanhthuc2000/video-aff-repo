<?php

namespace App\Services\Script;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\VideoProject;
use App\Support\SceneSettings;

/** Nội dung prompt viết kịch bản, dùng chung cho Claude API và Claude bản web. */
class ScriptPrompt
{
    public const SYSTEM = <<<'PROMPT'
    Bạn là người viết kịch bản video affiliate ngắn (Shopee Video, dọc 9:16) cho sản phẩm thời trang.

    Viết bằng tiếng Việt tự nhiên, giọng người thật đang giới thiệu đồ mình thích, câu ngắn, dễ đọc thành lời.

    Đầu ra:
    - hooks: đúng 5 câu mở đầu (mỗi câu dưới 12 từ), gây tò mò trong 2 giây đầu, mỗi câu theo một chủ đề khác nhau, chọn từ:
      Tò mò, Vấn đề – giải pháp, Người mua nói gì, Xu hướng, Cách phối đồ, Khoảnh khắc / cảm xúc, Chi tiết đáng tiền.
    - hook_themes: tên chủ đề của từng hook, cùng thứ tự với hooks.
    - recommended_hook: vị trí (0–4) của hook hợp nhất với sản phẩm và người xem, dùng để mở video.
    - scenes: đúng số cảnh và tổng thời lượng như phần "Yêu cầu video". Mỗi cảnh có:
      - phase: "after" (video thường luôn là after); video biến hình xem phần "Video biến hình".
      - visual: mô tả khung hình bằng tiếng Việt.
      - on_screen_text: chữ trên màn hình, tối đa 8 từ.
      - voiceover: lời đọc cho cảnh, khớp thời lượng (khoảng 3 từ mỗi giây).
      - image_prompt: prompt tiếng Anh để AI chỉnh ảnh người mẫu mặc đúng sản phẩm trong ảnh tham chiếu. Mở đầu bằng bối cảnh cụ thể (địa điểm, đồ vật xung quanh, thời điểm trong ngày), rồi đến tư thế, hành động, góc máy, ánh sáng; khung dọc 9:16.
        image_prompt không tả lại thiết kế sản phẩm (màu, hoạ tiết, chất liệu, độ dài, cổ áo, tay áo...) và không yêu cầu sửa sản phẩm: chỉ gọi là "the exact product from the product photos" kèm cách mặc / cầm theo mục "Cách dùng sản phẩm". Muốn ảnh kín đáo thì đổi tư thế, góc máy, khoảng cách, bối cảnh; KHÔNG viết thêm áo khoác, khăn, lớp lót hay đổi kiểu sản phẩm.
      - duration_ms: thời lượng cảnh; tổng các cảnh bằng tổng thời lượng yêu cầu.
    - Mỗi cảnh một bối cảnh khác hẳn nhau, không lặp địa điểm, như một ngày mặc sản phẩm.
      Ưu tiên các bối cảnh trong mục "Bối cảnh hợp với sản phẩm" (chọn theo tên và kiểu sản phẩm, nơi người mua thật sự sẽ mặc).
    - Đổi cả tư thế và góc máy giữa các cảnh (toàn thân đang bước đi, ngồi, xoay người, nửa người, cận chi tiết); không lặp cùng một kiểu đứng tạo dáng.
    - Chỉ có đúng MỘT sản phẩm đang bán trong mỗi cảnh. image_prompt không được nhắc túi, ví, mũ, kính, trang sức, điện thoại
      hay phụ kiện nào khác (trừ khi đó chính là sản phẩm). Sản phẩm luôn được mặc / đeo / cầm trên người mẫu ngay trong khung hình
      theo đúng mục "Cách dùng sản phẩm", không đặt rời, không lơ lửng, không "xuất hiện" giữa chừng.
    - Phong cách trang nhã, lịch sự như ảnh thời trang thương mại: KHÔNG gợi cảm, không sexy, không khiêu khích.
      Không mô tả hay nhấn vào cơ thể (ngực, eo, đùi, khe xẻ, phần hở, độ mỏng xuyên thấu), không góc máy thấp nhìn lên hay cận cơ thể.
      Với đồ ren, đồ mỏng, đồ ôm, đồ ngủ: tư thế kín đáo tự nhiên, góc máy ngang tầm mắt, cận chi tiết thì chụp vải trên móc/manơcanh hoặc trải phẳng.
    - Ánh sáng và thời điểm cũng nên khác nhau (nắng sáng, chiều vàng, đèn quán ấm, đèn văn phòng, đèn tối).
    - Video phải trông như người mua thật tự quay bằng điện thoại (UGC), không phải quảng cáo bóng bẩy: image_prompt tả một khoảnh khắc đời thường có thật (chỉnh váy trước gương, cầm ly cà phê, bước ra cửa, ngồi bậc thềm...), ánh sáng có sẵn ở nơi đó, góc chụp kiểu điện thoại (selfie gương, bạn chụp hộ ngang tầm mắt, dựng máy trên kệ); KHÔNG dùng các từ cinematic, studio, professional photoshoot, perfect, flawless, 8k, ultra-detailed, dreamy, glamour.
    - Lời đọc và chữ trên màn như người thật nói chuyện với bạn bè (tự nhiên, có cảm xúc, có thể dùng "nè", "nha", "xinh xỉu"), không giọng quảng cáo đọc thông số.
    - Cảnh cuối là kêu gọi mua: "xem màu, size trong giỏ hàng" (sản phẩm không có size: "xem chi tiết trong giỏ hàng").
    - caption: 1–2 câu cho bài đăng.
    - hashtags: 3–6 hashtag, có dấu #.

    Quy tắc:
    - Chỉ nói những gì có trong thông tin sản phẩm, ảnh và đánh giá. Không bịa chất liệu ("lụa cao cấp"), công dụng ("không lộ", "che bụng"), số liệu bán hàng hay giá cố định, giảm giá.
    - KHÔNG nhắc ưu đãi, khuyến mãi, giảm giá, voucher, sale, deal ở bất kỳ đâu (hook, chữ trên màn hình, lời đọc, caption, hashtag).
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

    /** Phần tin nhắn gửi Claude: thông tin sản phẩm, bối cảnh gợi ý và yêu cầu độ dài video. */
    public static function userText(VideoProject $project): string
    {
        $settings = $project->videoSettings();
        $perScene = round($settings['seconds'] / max(1, $settings['scenes']), 1);

        $category = ProductCategory::detect($project->product);

        return self::productInfo($project->product)."\n\n"
            .($category ? "Loại sản phẩm: {$category->name}. Cách dùng sản phẩm trong ảnh: {$category->usage()}.\n\n" : '')
            .self::settingsText($project)
            .self::outfitsText($project, $settings['scenes'])
            ."Yêu cầu video: đúng {$settings['scenes']} cảnh, tổng khoảng {$settings['seconds']} giây (mỗi cảnh khoảng {$perScene} giây; cảnh cuối là kêu gọi mua)."
            .self::piecesText($project)
            .($project->isTransformation() ? "\n\n".self::TRANSFORMATION : '')
            .($project->one_take ? "\n\n".self::ONE_TAKE : '');
    }

    /** Bối cảnh: do người dùng cung cấp (cố định cho từng cảnh) hoặc gợi ý theo sản phẩm. */
    private static function settingsText(VideoProject $project): string
    {
        $backgrounds = $project->chosenBackgrounds();
        if ($backgrounds->isEmpty()) {
            return 'Bối cảnh hợp với sản phẩm: '.implode('; ', SceneSettings::suggestFor($project->product)).".\n\n";
        }

        $settings = $project->videoSettings();
        $lines = [];
        for ($i = 0; $i < $settings['scenes']; $i++) {
            $lines[] = 'Cảnh '.($i + 1).': '.$backgrounds[$i % $backgrounds->count()]->label();
        }

        return "Bối cảnh do người dùng cung cấp (có ảnh nền thật, gửi kèm khi tạo ảnh). Dùng ĐÚNG bối cảnh này cho từng cảnh, thay cho quy tắc mỗi cảnh một địa điểm khác:\n"
            .implode("\n", $lines)."\n"
            .'Với các cảnh này, image_prompt mở đầu bằng "In the exact location shown in the background photo (<tên bối cảnh bằng tiếng Anh>)", '
            .'không tả thêm địa điểm, đồ đạc hay người khác; tạo khác biệt giữa các cảnh bằng tư thế, hành động, góc máy và ánh sáng.'."\n\n";
    }

    /** Mỗi cảnh một bộ đồ: danh sách bộ đồ, cảnh nào mặc bộ nào và thông tin các sản phẩm khác. */
    private static function outfitsText(VideoProject $project, int $sceneCount): string
    {
        $outfits = $project->outfitList();
        if ($outfits === [] || $project->one_take) {
            return '';
        }

        $lines = [];
        foreach ($outfits as $i => $outfit) {
            $lines[] = 'Bộ '.($i + 1).': '.$outfit['product']->name.($outfit['color'] ? " – màu {$outfit['color']}" : '');
        }

        // Cảnh nào mặc bộ nào (giống lúc lưu kịch bản); video biến hình thì cảnh before không mặc nên chỉ nói lần lượt.
        $mapping = '';
        if (! $project->isTransformation()) {
            $pieces = $project->shotPieces();
            $rows = [];
            for ($i = 0; $i < $sceneCount; $i++) {
                $rows[] = 'Cảnh '.($i + 1).': Bộ '.((($pieces[$i]['shot'] ?? $i) % count($outfits)) + 1);
            }
            $mapping = "Cảnh mặc bộ nào:\n".implode("\n", $rows)."\n";
        }

        $others = collect($outfits)->pluck('product')->unique('id')->reject(fn ($product) => $product->id === $project->product_id)
            ->map(fn ($product) => self::productInfo($product))->implode("\n\n");

        return "Mỗi cảnh một bộ đồ (video đổi đồ / outfit change). Các cảnh mặc sản phẩm dùng lần lượt các bộ sau:\n"
            .implode("\n", $lines)."\n".$mapping
            .'Quy tắc: mỗi cảnh chỉ mặc đúng bộ của cảnh đó (ảnh sản phẩm đúng bộ được gửi kèm khi tạo ảnh), image_prompt và visual không tả chi tiết thiết kế hay màu khác; '
            .'cảnh đổi sang bộ mới mở đầu bằng khoảnh khắc chuyển đồ (xoay người, che camera, búng tay); on_screen_text / voiceover có thể gọi tên ngắn hoặc màu của bộ đang mặc; '
            .'caption và hashtag nhắc đủ các sản phẩm.'."\n\n"
            .($others !== '' ? "Thông tin các sản phẩm khác trong video:\n{$others}\n\n" : '');
    }

    /** Video mẫu nhiều shot: độ dài từng cảnh theo shot (sao chép chuyển động từng shot). */
    private static function piecesText(VideoProject $project): string
    {
        $pieces = $project->shotPieces();
        if ($pieces === []) {
            return '';
        }

        $rows = array_map(fn (array $piece, int $i) => sprintf('Cảnh %d: %.1f giây (shot %d)', $i + 1, $piece['end'] - $piece['start'], $piece['shot'] + 1), $pieces, array_keys($pieces));

        return "\nĐộ dài từng cảnh theo các shot của video mẫu (dùng đúng các số này cho duration_ms):\n".implode("\n", $rows);
    }

    /** Video một cảnh quay liền: các cảnh là các nhịp nối tiếp của cùng một cảnh quay. */
    public const ONE_TAKE = <<<'PROMPT'
    Video MỘT CẢNH QUAY LIỀN (one take, không cắt cảnh), thay cho quy tắc mỗi cảnh một bối cảnh / góc máy khác:
    - Cùng một địa điểm, cùng ánh sáng, cùng bộ đồ từ đầu đến cuối; máy quay điện thoại cầm tay đi theo người mẫu.
    - Mỗi phần tử của scenes là một nhịp khoảng 5 giây nối tiếp liền mạch: nhịp sau bắt đầu đúng tư thế, vị trí cuối nhịp trước
      (bước tiếp, xoay người, tiến lại gần camera khoe chi tiết, ngồi xuống, đứng dậy, vẫy tay chào...). Không nhảy địa điểm hay đổi góc máy đột ngột.
    - Chỉ image_prompt của cảnh 1 dùng để tạo ảnh (các nhịp sau lấy khung cuối clip trước): image_prompt cảnh 1 tả bối cảnh và tư thế mở đầu;
      các cảnh sau image_prompt chỉ cần tóm tắt nhịp đó.
    - visual tả rõ hành động trong nhịp (AI làm clip theo visual), ví dụ "Bước chậm về phía camera, tay vuốt nhẹ tà váy".
    - Lời đọc nối liền như một người nói liên tục, không ngắt theo cảnh.
    PROMPT;

    /** Hướng dẫn thêm cho video biến hình (glow-up), kiểu video hay chạy trên Shopee Video / TikTok. */
    public const TRANSFORMATION = <<<'PROMPT'
    Video biến hình (glow-up): người xem thấy cô gái "trước" rồi bất ngờ "sau" khi mặc sản phẩm.
    - 1–2 cảnh đầu phase "before": dùng ảnh "trước" (đồ cũ, mặt mộc, tóc rối). Bối cảnh ở nhà / phòng ngủ, biểu cảm chán, uể oải.
      Trang phục, tóc, mặt của cảnh before phải GIỮ NGUYÊN y như ảnh "trước": image_prompt và visual KHÔNG được tả hay đổi quần áo,
      kiểu tóc, màu tóc, phụ kiện, makeup (không viết "áo thun cũ", "đồ ngủ"...). Chỉ tả bối cảnh, tư thế, biểu cảm, góc máy, ánh sáng.
      KHÔNG mặc sản phẩm. Không chế giễu ngoại hình.
    - Cảnh tiếp theo là khoảnh khắc chuyển (búng tay, che camera, xoay người, vuốt tóc) và từ đây phase "after":
      makeup nhẹ, tóc gọn, mặc đúng sản phẩm, tự tin; các cảnh after sau đó theo bối cảnh hợp với sản phẩm như bình thường.
    - Ít nhất một nửa số cảnh là after. Hook hướng về sự biến hình (ví dụ "Từ ở nhà lôi thôi đến đi tiệc chỉ với 1 chiếc váy").
    PROMPT;

    /** Prompt đầy đủ để dán vào claude.ai (không có structured outputs nên yêu cầu JSON theo mẫu). */
    public static function forWeb(VideoProject $project): string
    {
        $example = json_encode([
            'hooks' => ['...', '...', '...', '...', '...'],
            'hook_themes' => ['Tò mò', '...', '...', '...', '...'],
            'recommended_hook' => 0,
            'scenes' => [[
                'phase' => 'after',
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
            .self::userText($project)."\n\n"
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
