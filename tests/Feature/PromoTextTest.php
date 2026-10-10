<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Script\ScriptPrompt;
use App\Services\Script\ScriptSchema;
use App\Support\PromoText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromoTextTest extends TestCase
{
    use RefreshDatabase;

    public function test_strips_promo_phrases()
    {
        $this->assertSame('Xem màu, size trong giỏ hàng', PromoText::strip('Xem màu, size, ưu đãi trong giỏ hàng'));
        $this->assertSame('Xem màu, size trong giỏ hàng nha.', PromoText::strip('Xem màu, size và ưu đãi trong giỏ hàng nha.'));
        $this->assertSame('Xem màu, size ở giỏ hàng!', PromoText::strip('Xem màu, size và các ưu đãi ở giỏ hàng!'));
        $this->assertSame('màu, size đầy đủ trong giỏ', PromoText::strip('Ưu đãi, màu, size đầy đủ trong giỏ'));
        $this->assertSame('Vải mềm, mặc mát', PromoText::strip('Vải mềm, mặc mát'));
        $this->assertSame(['#damdutiec'], PromoText::stripHashtags(['#damdutiec', '#sale', '#uudai', '#giamgia']));
    }

    public function test_script_normalize_removes_promos_and_prompt_forbids_them()
    {
        $script = ScriptSchema::normalize([
            'hooks' => ['Mẫu hot, ưu đãi hôm nay'],
            'scenes' => [['visual' => 'v', 'image_prompt' => 'p', 'on_screen_text' => 'Xem màu, size, ưu đãi trong giỏ hàng', 'voiceover' => 'Xem màu, size và ưu đãi trong giỏ hàng nha.']],
            'caption' => 'Đầm đẹp. Xem màu, size, ưu đãi trong giỏ hàng!',
            'hashtags' => ['#dam', '#flashsale'],
        ]);

        $this->assertSame('Xem màu, size trong giỏ hàng', $script['scenes'][0]['on_screen_text']);
        $this->assertSame('Xem màu, size trong giỏ hàng nha.', $script['scenes'][0]['voiceover']);
        $this->assertSame('Đầm đẹp. Xem màu, size trong giỏ hàng!', $script['caption']);
        $this->assertSame(['#dam'], $script['hashtags']);
        $this->assertStringContainsString('KHÔNG nhắc ưu đãi', ScriptPrompt::SYSTEM);
        $this->assertStringNotContainsString('size, ưu đãi', ScriptPrompt::SYSTEM);
    }

    public function test_end_card_call_to_action_depends_on_product_type()
    {
        $this->assertSame('Xem màu, size trong giỏ hàng', PromoText::callToAction(Product::factory()->make(['name' => 'Đầm dự tiệc ren'])));
        $this->assertSame('Xem màu, size trong giỏ hàng', PromoText::callToAction(Product::factory()->make(['name' => 'Giày sneaker'])));
        $this->assertSame('Xem chi tiết trong giỏ hàng', PromoText::callToAction(Product::factory()->make(['name' => 'Ốp lưng iPhone'])));
    }
}
