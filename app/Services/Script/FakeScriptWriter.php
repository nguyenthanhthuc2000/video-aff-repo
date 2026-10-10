<?php

namespace App\Services\Script;

use App\Models\VideoProject;
use App\Support\PromoText;
use App\Support\SceneSettings;

/** Kịch bản mẫu, dùng khi chưa có khoá Claude và trong test. Theo đúng số cảnh / thời lượng đã cài. */
class FakeScriptWriter implements ScriptWriter
{
    public function write(VideoProject $project): array
    {
        $name = $project->product->name;
        $settings = $project->videoSettings();
        $places = SceneSettings::suggestFor($project->product);
        $duration = (int) round($settings['seconds'] * 1000 / $settings['scenes']);

        $scenes = [];
        for ($i = 0; $i < $settings['scenes']; $i++) {
            $last = $i === $settings['scenes'] - 1;
            $place = $places[$i % count($places)];
            $scenes[] = [
                'phase' => $project->isTransformation() && $i === 0 ? 'before' : 'after',
                'visual' => $last ? 'Người mẫu mỉm cười, chỉ xuống giỏ hàng' : "Người mẫu mặc sản phẩm, {$place}",
                'on_screen_text' => $last ? PromoText::callToAction($project->product) : ucfirst($place),
                'voiceover' => $last ? PromoText::callToAction($project->product).' nha.' : "Mặc đi {$place} là hợp.",
                'image_prompt' => "model wearing the product, {$place}, natural light, 9:16",
                'duration_ms' => $duration,
            ];
        }

        return ScriptSchema::normalize([
            'hooks' => [
                "Mặc {$name} đi đâu cũng được khen",
                'Người mua nói gì về mẫu này?',
                'Mẫu đang được săn nhiều tuần này',
                'Một món phối được cả tuần',
                'Nhìn kỹ chi tiết này nha',
            ],
            'hook_themes' => ['Tò mò', 'Người mua nói gì', 'Xu hướng', 'Cách phối đồ', 'Chi tiết đáng tiền'],
            'recommended_hook' => 0,
            'scenes' => $scenes,
            'caption' => "{$name}: ".mb_strtolower(PromoText::callToAction($project->product)).'.',
            'hashtags' => ['#shopee', '#thoitrang', '#review'],
        ]);
    }
}
