<?php

namespace Tests\Feature;

use App\Enums\MotionStyle;
use App\Enums\ScenePhase;
use App\Jobs\GenerateSceneImageJob;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Scene;
use App\Models\VideoProject;
use App\Services\Script\ScriptPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductPromptTest extends TestCase
{
    use RefreshDatabase;

    private function hatScene(array $attributes = []): Scene
    {
        $product = Product::factory()->create(['name' => 'Mũ Len Lưỡi Trai Dệt Kim']);
        $project = VideoProject::factory()->for($product)->create();

        return Scene::factory()->for($project)->create(['visual' => 'Dạo phố mùa thu', ...$attributes]);
    }

    public function test_image_prompt_names_the_product_and_forbids_extra_items()
    {
        $scene = $this->hatScene();

        $prompt = GenerateSceneImageJob::prompt($scene->image_prompt, null, ProductCategory::productLine($scene->videoProject->product));

        $this->assertStringContainsString('The product is "Mũ Len Lưỡi Trai Dệt Kim"', $prompt);
        $this->assertStringContainsString('a hat on her head', $prompt);
        $this->assertStringContainsString('do not add any other bags, wallets', $prompt);
        $this->assertStringContainsString('never floating in the air', $prompt);
        $this->assertStringNotContainsString('garment', $prompt);
    }

    public function test_motion_prompt_keeps_product_from_first_frame_without_extra_items()
    {
        $scene = $this->hatScene();

        foreach (MotionStyle::cases() as $style) {
            $prompt = $style->prompt($scene);
            $this->assertStringContainsString('visible from the very first frame', $prompt, $style->value);
            $this->assertStringContainsString('never appears, disappears, floats in the air', $prompt, $style->value);
            $this->assertStringNotContainsString('bag on the shoulder', $prompt, $style->value);
            $this->assertStringNotContainsString('puts the product on', $prompt, $style->value);
        }
        $this->assertStringContainsString('is already wearing the product', MotionStyle::TryOn->prompt($scene));

        $before = $this->hatScene(['phase' => ScenePhase::Before]);
        $this->assertStringContainsString('not wearing or holding the product yet', MotionStyle::Groove->prompt($before));
    }

    public function test_script_prompt_tells_claude_how_the_product_is_used()
    {
        $scene = $this->hatScene();

        $text = ScriptPrompt::userText($scene->videoProject);

        $this->assertStringContainsString('Loại sản phẩm: Mũ, kính, trang sức, phụ kiện đeo', $text);
        $this->assertStringContainsString('Cách dùng sản phẩm trong ảnh: wearing the exact accessory', $text);
        $this->assertStringContainsString('Chỉ có đúng MỘT sản phẩm', ScriptPrompt::SYSTEM);
    }
}
