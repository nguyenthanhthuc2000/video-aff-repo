<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_user_sees_only_their_products()
    {
        $user = User::factory()->create();
        Product::factory()->for($user)->create(['name' => 'Của tôi']);
        Product::factory()->create(['name' => 'Của người khác']);

        $this->actingAs($user)->get('/products')
            ->assertInertia(fn (Assert $page) => $page
                ->component('products/index')
                ->has('products', 1)
                ->where('products.0.name', 'Của tôi'));
    }

    public function test_user_can_create_product_with_images()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/products', [
            'name' => 'Chân váy lụa vân gỗ',
            'shopee_url' => 'https://shopee.vn/product/1/2',
            'price' => 259000,
            'details' => ['sizes' => 'XS–XL', 'material' => 'Lụa vân gỗ'],
            'images' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
        ]);

        $product = $user->products()->first();
        $response->assertRedirect(route('products.edit', $product));
        $this->assertSame('XS–XL', $product->details['sizes']);
        $this->assertCount(2, $product->images);
        $this->assertSame(1, $product->images()->where('is_primary', true)->count());
        Storage::disk('public')->assertExists($product->images->first()->path);
    }

    public function test_product_needs_at_least_one_image_and_at_most_five()
    {
        config(['videoaff.product_images_max' => 5]);
        $user = User::factory()->create();

        $this->actingAs($user)->post('/products', ['name' => 'Không ảnh'])
            ->assertSessionHasErrors('images');

        $this->actingAs($user)->post('/products', [
            'name' => 'Quá nhiều ảnh',
            'images' => array_map(fn ($i) => UploadedFile::fake()->image("$i.jpg"), range(1, 6)),
        ])->assertSessionHasErrors('images');
    }

    public function test_update_respects_image_limit()
    {
        config(['videoaff.product_images_max' => 5]);
        $product = Product::factory()->create();
        ProductImage::factory()->count(4)->for($product)->create();

        $this->actingAs($product->user)->put(route('products.update', $product), [
            'name' => 'Tên mới',
            'images' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
        ])->assertSessionHasErrors('images');

        $this->actingAs($product->user)->put(route('products.update', $product), [
            'name' => 'Tên mới',
            'images' => [UploadedFile::fake()->image('a.jpg')],
        ])->assertSessionHasNoErrors();

        $this->assertSame('Tên mới', $product->fresh()->name);
        $this->assertCount(5, $product->images()->get());
    }

    public function test_other_users_cannot_edit_or_delete()
    {
        $product = Product::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($other)->get(route('products.edit', $product))->assertForbidden();
        $this->actingAs($other)->put(route('products.update', $product), ['name' => 'x'])->assertForbidden();
        $this->actingAs($other)->delete(route('products.destroy', $product))->assertForbidden();
    }

    public function test_make_primary_and_delete_image()
    {
        $product = Product::factory()->create();
        $first = ProductImage::factory()->for($product)->primary()->create();
        $second = ProductImage::factory()->for($product)->create();

        $this->actingAs($product->user)->post(route('product-images.primary', $second));
        $this->assertTrue($second->fresh()->is_primary);
        $this->assertFalse($first->fresh()->is_primary);

        $this->actingAs($product->user)->delete(route('product-images.destroy', $second));
        $this->assertModelMissing($second);
        $this->assertTrue($first->fresh()->is_primary);

        $this->actingAs($product->user)->delete(route('product-images.destroy', $first))
            ->assertSessionHasErrors('images');
        $this->assertModelExists($first);
    }

    public function test_delete_product()
    {
        $product = Product::factory()->create();

        $this->actingAs($product->user)->delete(route('products.destroy', $product))
            ->assertRedirect(route('products.index'));

        $this->assertModelMissing($product);
    }
}
