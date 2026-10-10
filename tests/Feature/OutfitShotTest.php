<?php

namespace Tests\Feature;

use App\Jobs\GenerateSceneImageJob;
use App\Models\FashionModel;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\SampleVideo;
use App\Models\Scene;
use App\Models\User;
use App\Models\VideoProject;
use App\Services\Script\ScriptPrompt;
use App\Services\Video\ShotDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OutfitShotTest extends TestCase
{
    use RefreshDatabase;

    private function script(int $scenes): array
    {
        return [
            'hooks' => ['H'], 'hook_themes' => [], 'recommended_hook' => 0, 'caption' => 'C', 'hashtags' => ['#a'],
            'scenes' => array_map(fn ($i) => [
                'duration_ms' => 3000, 'visual' => "V{$i}", 'on_screen_text' => "T{$i}", 'voiceover' => "O{$i}", 'image_prompt' => "Prompt {$i}",
            ], range(1, $scenes)),
        ];
    }

    public function test_cuts_become_shots_and_long_shots_split_into_pieces()
    {
        // Cắt ở 0,21s (chớp đầu video, quá ngắn nên bỏ) và 3,01s.
        $shots = ShotDetector::shotsFromCuts([0.21, 3.01], 17.09);
        $this->assertSame([['start' => 0.0, 'end' => 3.01], ['start' => 3.01, 'end' => 17.09]], $shots);
        $this->assertSame([], ShotDetector::shotsFromCuts([], 10));

        $video = SampleVideo::factory()->create(['duration_ms' => 17090, 'shots' => $shots]);
        $this->assertSame([
            ['start' => 0.0, 'end' => 3.01, 'shot' => 0],
            ['start' => 3.01, 'end' => 7.7, 'shot' => 1],
            ['start' => 7.7, 'end' => 12.4, 'shot' => 1],
            ['start' => 12.4, 'end' => 17.09, 'shot' => 1],
        ], $video->pieces());
    }

    public function test_each_shot_wears_its_own_outfit()
    {
        $project = VideoProject::factory()->create();
        $other = Product::factory()->for($project->user)->create(['name' => 'Áo khoác lông']);
        $red = ProductImage::factory()->for($project->product)->create(['path' => 'products/red.jpg']);
        $sample = SampleVideo::factory()->for($project->user)->create([
            'duration_ms' => 17090,
            'shots' => [['start' => 0, 'end' => 3.01], ['start' => 3.01, 'end' => 17.09]],
        ]);
        $project->update([
            'sample_video_id' => $sample->id,
            'outfits' => [
                ['product_id' => $project->product_id, 'product_image_id' => $red->id, 'color' => 'Đỏ'],
                ['product_id' => $other->id, 'product_image_id' => null, 'color' => null],
            ],
        ]);

        $settings = $project->videoSettings();
        $this->assertSame(4, $settings['scenes']);
        $this->assertSame(17, $settings['seconds']);
        $prompt = ScriptPrompt::userText($project);
        $this->assertStringContainsString("Cảnh 1: Bộ 1\nCảnh 2: Bộ 2\nCảnh 3: Bộ 2\nCảnh 4: Bộ 2", $prompt);
        $this->assertStringContainsString('Áo khoác lông', $prompt);
        $this->assertStringContainsString('Cảnh 2: 4.7 giây (shot 2)', $prompt);

        $project->applyScript($this->script(4));
        $scenes = $project->scenes()->orderBy('position')->get();

        $this->assertSame([3010, 4690, 4700, 4690], $scenes->pluck('duration_ms')->all());
        $this->assertSame([$project->product_id, $other->id, $other->id, $other->id], $scenes->pluck('product_id')->all());
        $this->assertSame($red->id, $scenes[0]->product_image_id);
        $this->assertSame(['start' => 7.7, 'length' => 4.7], $scenes[2]->motionSegment());

        // Ảnh cảnh 1: đúng màu đỏ của sản phẩm chính; cảnh 2: sản phẩm khác.
        $this->assertStringContainsString('Use exactly the "Đỏ" color / variant of this product, as in the attached product photo.', GenerateSceneImageJob::scenePrompt($scenes[0]));
        $this->assertStringContainsString('"Áo khoác lông"', GenerateSceneImageJob::scenePrompt($scenes[1]));
    }

    public function test_create_project_with_outfits_validates_products_and_images()
    {
        Queue::fake();
        $user = User::factory()->create();
        $product = Product::factory()->for($user)->create();
        $second = Product::factory()->for($user)->create();
        $model = FashionModel::factory()->for($user)->create();
        $foreignImage = ProductImage::factory()->for(Product::factory())->create();
        $notApproved = Product::factory()->for($user)->imagesNotApproved()->create();

        $base = ['product_id' => $product->id, 'fashion_model_id' => $model->id];
        $this->actingAs($user)->post(route('projects.store'), [...$base, 'outfits' => [['product_id' => $product->id, 'product_image_id' => $foreignImage->id]]])
            ->assertSessionHasErrors('outfits.0.product_image_id');
        $this->actingAs($user)->post(route('projects.store'), [...$base, 'outfits' => [['product_id' => $notApproved->id]]])
            ->assertSessionHasErrors('outfits.0.product_id');

        $this->actingAs($user)->post(route('projects.store'), [...$base, 'outfits' => [
            ['product_id' => $product->id, 'color' => ' Be '],
            ['product_id' => $second->id],
        ]])->assertSessionHasNoErrors();

        $this->assertSame([
            ['product_id' => $product->id, 'product_image_id' => null, 'color' => 'Be'],
            ['product_id' => $second->id, 'product_image_id' => null, 'color' => null],
        ], $user->videoProjects()->sole()->outfits);
    }

    public function test_change_scene_outfit()
    {
        $project = VideoProject::factory()->create();
        $scene = Scene::factory()->for($project)->create();
        $other = Product::factory()->for($project->user)->create();
        $image = ProductImage::factory()->for($other)->create();
        $strangers = Product::factory()->create();

        $this->actingAs($project->user)->put(route('scenes.outfit', $scene), ['product_id' => $strangers->id])->assertSessionHasErrors('product_id');
        $this->actingAs($project->user)->put(route('scenes.outfit', $scene), ['product_id' => null, 'product_image_id' => $image->id])
            ->assertSessionHasErrors('product_image_id');

        $this->actingAs($project->user)->put(route('scenes.outfit', $scene), ['product_id' => $other->id, 'product_image_id' => $image->id, 'color' => 'Xanh'])
            ->assertSessionHasNoErrors();
        $scene->refresh();
        $this->assertSame([$other->id, $image->id, 'Xanh'], [$scene->product_id, $scene->product_image_id, $scene->outfit_color]);
        $this->assertTrue($scene->outfitProduct()->is($other));

        $this->actingAs(User::factory()->create())->put(route('scenes.outfit', $scene), ['product_id' => null])->assertForbidden();
    }

    public function test_products_page_offers_combined_video_form()
    {
        $user = User::factory()->create();
        $product = Product::factory()->for($user)->create(['details' => ['colors' => 'Đen, Trắng / Be, Hoa vàng (chỉ được ghi trong mô tả, trang không có danh sách màu riêng)']]);
        ProductImage::factory()->for($product)->create();
        Product::factory()->for($user)->imagesNotApproved()->create();

        $this->actingAs($user)->get(route('products.index'))->assertInertia(fn ($page) => $page
            ->component('products/index')
            ->has('products', 2)
            ->has('fashionModels')
            ->has('outfitProducts', 1)
            ->where('outfitProducts.0.colors', ['Đen', 'Trắng', 'Be', 'Hoa vàng'])
            ->has('outfitProducts.0.images', 1));
    }

    public function test_project_page_lists_every_product_with_images_and_links()
    {
        $project = VideoProject::factory()->create();
        $other = Product::factory()->for($project->user)->create();
        $main = ProductImage::factory()->for($project->product)->count(4)->create();
        $chosen = ProductImage::factory()->for($other)->count(2)->create()->last();
        $project->update(['outfits' => [
            ['product_id' => $project->product_id, 'product_image_id' => null, 'color' => null],
            ['product_id' => $other->id, 'product_image_id' => $chosen->id, 'color' => 'Hồng'],
            ['product_id' => $project->product_id, 'product_image_id' => null, 'color' => 'Đen'],
        ]]);
        $project->applyScript($this->script(3));

        $this->actingAs($project->user)->get(route('projects.show', $project))->assertInertia(fn ($page) => $page
            ->has('project.video_products', 2)
            ->where('project.video_products.0.outfit_numbers', [1, 3])
            ->where('project.video_products.0.scenes', [1, 3])
            ->where('project.video_products.0.colors', ['Đen'])
            ->where('project.video_products.0.shopee_url', $project->product->shopee_url)
            ->has('project.video_products.0.images', 4)
            ->where('project.video_products.0.images.3.used', false)
            ->where('project.video_products.1.outfit_numbers', [2])
            ->where('project.video_products.1.url', route('products.edit', $other))
            ->where('project.video_products.1.images.0.used', false)
            ->where('project.video_products.1.images.1.used', true));
    }
}
