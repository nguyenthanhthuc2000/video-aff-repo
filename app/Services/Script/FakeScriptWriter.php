<?php

namespace App\Services\Script;

use App\Models\VideoProject;

/** Kịch bản mẫu, dùng khi chưa có khoá Claude và trong test. */
class FakeScriptWriter implements ScriptWriter
{
    public function write(VideoProject $project): array
    {
        $name = $project->product->name;

        return ScriptSchema::normalize([
            'hooks' => [
                "Mặc {$name} đi đâu cũng được khen",
                'Một món mà phối được cả tuần',
                'Đừng mua trước khi xem video này',
            ],
            'scenes' => [
                ['visual' => 'Người mẫu đứng toàn thân, xoay nhẹ', 'on_screen_text' => 'Hook', 'voiceover' => 'Mẫu này đang hot lắm nè.', 'image_prompt' => 'full body, model wearing the product, studio light, 9:16', 'duration_ms' => 3000],
                ['visual' => 'Cận chất vải', 'on_screen_text' => 'Chất vải', 'voiceover' => 'Nhìn kỹ chất vải nha.', 'image_prompt' => 'close-up of the fabric texture, model wearing the product, 9:16', 'duration_ms' => 3000],
                ['visual' => 'Người mẫu đi dạo phố', 'on_screen_text' => 'Đi chơi', 'voiceover' => 'Đi chơi cuối tuần là hợp.', 'image_prompt' => 'model wearing the product walking on a city street, 9:16', 'duration_ms' => 3000],
                ['visual' => 'Bảng size và giỏ hàng', 'on_screen_text' => 'Xem màu, size, ưu đãi trong giỏ hàng', 'voiceover' => 'Xem màu, size và ưu đãi trong giỏ hàng nha.', 'image_prompt' => 'model wearing the product, smiling, plain background, 9:16', 'duration_ms' => 3000],
            ],
            'caption' => "{$name}: xem màu, size, ưu đãi trong giỏ hàng.",
            'hashtags' => ['#shopee', '#thoitrang', '#review'],
        ]);
    }
}
