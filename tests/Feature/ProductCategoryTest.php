<?php

namespace Tests\Feature;

use App\Enums\MotionStyle;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\VideoProject;
use App\Support\SceneSettings;
use Database\Seeders\ProductCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductCategoryTest extends TestCase
{
    use RefreshDatabase;

    private function categoryOf(string $name): string
    {
        return ProductCategory::detect(Product::factory()->make(['name' => $name]))->key;
    }

    public function test_master_data_is_seeded_by_migration()
    {
        $this->assertSame(14, ProductCategory::count());
        $this->assertSame('clothing', ProductCategory::where('key', 'clothing_party')->first()->parent->key);
    }

    public function test_detects_category_from_product_name()
    {
        $this->assertSame('phone', $this->categoryOf('Ốp Lưng iPhone TPU Chống Bẩn'));
        $this->assertSame('wearable', $this->categoryOf('Mũ Len Lưỡi Trai Dệt Kim'));
        $this->assertSame('shoes', $this->categoryOf('Giày sneaker nữ đế cao'));
        $this->assertSame('bag', $this->categoryOf('Túi xách nữ đeo chéo'));
        $this->assertSame('beauty', $this->categoryOf('Son kem lì mềm mượt'));
        $this->assertSame('other', $this->categoryOf('Bình giữ nhiệt 500ml'));
        // Giữ dấu: "kèm" không phải "kem"; loại = từ khoá xuất hiện sớm nhất.
        $this->assertSame('clothing_party', $this->categoryOf('Đầm dự tiệc chất ren kèm phụ kiện cài ngực'));
        $this->assertSame('clothing_sleep', $this->categoryOf('Set đồ ngủ nữ mặc nhà Pijama lụa'));
        $this->assertSame('clothing', $this->categoryOf('Váy Maxi kèm quần trong'));
        $this->assertSame('clothing_outer', $this->categoryOf('Áo khoác kèm khăn choàng'));
    }

    public function test_suggestions_come_from_database_and_inherit_from_parent()
    {
        $party = Product::factory()->make(['name' => 'Đầm dự tiệc ren']);
        $this->assertSame(MotionStyle::TiktokDance, MotionStyle::suggestFor($party)[0]);
        $this->assertContains('sảnh khách sạn lung linh đèn', SceneSettings::suggestFor($party));

        $phone = Product::factory()->make(['name' => 'Ốp lưng iPhone']);
        $this->assertSame([MotionStyle::DemoUse, MotionStyle::Unboxing, MotionStyle::ProductShowcase], MotionStyle::suggestFor($phone));

        // Sửa master data trong DB thì gợi ý đổi theo, không cần sửa code.
        ProductCategory::where('key', 'phone')->update(['motion_styles' => json_encode(['product_showcase']), 'scene_settings' => json_encode(['bàn gaming'])]);
        $this->assertSame([MotionStyle::ProductShowcase], MotionStyle::suggestFor($phone));
        $this->assertSame(['bàn gaming'], SceneSettings::suggestFor($phone));

        // Chạy lại seeder không ghi đè dữ liệu đã chỉnh.
        (new ProductCategorySeeder)->run();
        $this->assertSame(['bàn gaming'], SceneSettings::suggestFor($phone));
        $this->assertSame(14, ProductCategory::count());
    }

    public function test_product_page_passes_motion_suggestions()
    {
        $product = Product::factory()->create(['name' => 'Mũ len nữ']);

        $this->actingAs($product->user)->get(route('products.edit', $product))->assertInertia(fn (Assert $page) => $page
            ->where('motionSuggestions', ['try_on', 'product_showcase', 'groove'])
            ->where('productCategory', 'Mũ, kính, trang sức, phụ kiện đeo'));
    }

    public function test_products_index_cards_show_counts_and_category()
    {
        $product = Product::factory()->create(['name' => 'Mũ len nữ']);
        $product->images()->create(['path' => 'products/a.jpg', 'is_primary' => true]);
        VideoProject::factory()->for($product->user)->for($product)->count(2)->create();

        $this->actingAs($product->user)->get(route('products.index'))->assertInertia(fn (Assert $page) => $page
            ->where('products.0.images_count', 1)
            ->where('products.0.video_projects_count', 2)
            ->where('products.0.category', 'Mũ, kính, trang sức, phụ kiện đeo'));
    }
}
