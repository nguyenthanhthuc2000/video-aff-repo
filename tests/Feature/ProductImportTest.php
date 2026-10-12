<?php

namespace Tests\Feature;

use App\Enums\ProductImportStatus;
use App\Jobs\ImportProductJob;
use App\Models\FashionModel;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\ProductImport\ClaudeProductExtractor;
use App\Services\ProductImport\FakeProductExtractor;
use App\Services\ProductImport\PageSnapshot;
use App\Services\ProductImport\ProductExtractor;
use App\Services\ProductImport\ProductExtractPrompt;
use App\Services\ProductImport\RemoteImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductImportTest extends TestCase
{
    use RefreshDatabase;

    private const CDN = 'https://down-vn.img.susercontent.com/file/';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function snapshot(array $overrides = []): array
    {
        return [
            'url' => 'https://shopee.vn/Chan-vay-lua-i.1.2',
            'title' => 'Chân váy lụa vân gỗ | Shopee Việt Nam',
            'text' => 'Chân váy lụa vân gỗ ₫259.000 Size S M L ...',
            'name' => '',
            'price' => 259000,
            'images' => [self::CDN.'a', self::CDN.'b'],
            ...$overrides,
        ];
    }

    private function jpegFake(): void
    {
        Http::fake(['*' => Http::response(UploadedFile::fake()->image('a.jpg')->getContent(), 200, ['Content-Type' => 'image/jpeg'])]);
    }

    public function test_extractor_binding_follows_script_driver()
    {
        config(['videoaff.script_driver' => 'web']);
        $this->assertInstanceOf(FakeProductExtractor::class, app(ProductExtractor::class));

        config(['videoaff.script_driver' => 'claude', 'videoaff.claude.api_key' => 'test-key']);
        $this->assertInstanceOf(ClaudeProductExtractor::class, app(ProductExtractor::class));
    }

    public function test_import_creates_product_immediately_and_queues_job()
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('products.import'), $this->snapshot([
            'images' => [self::CDN.'a', 'https://evil.com/x.jpg'],
        ]));

        $product = $user->products()->sole();
        $response->assertRedirect(route('products.edit', $product));
        $this->assertSame('Chân váy lụa vân gỗ', $product->name);
        $this->assertSame(259000, $product->price);
        $this->assertSame(ProductImportStatus::Importing, $product->import_status);
        $this->assertSame([self::CDN.'a'], $product->import_snapshot['images']);
        $this->assertFalse($product->imagesApproved());
        Queue::assertPushed(ImportProductJob::class, fn ($job) => $job->product->is($product));
    }

    public function test_import_needs_shopee_images()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('products.import'), $this->snapshot(['images' => []]))->assertSessionHasErrors('images');
        $this->actingAs($user)->post(route('products.import'), $this->snapshot(['images' => ['https://evil.com/a.jpg']]))->assertSessionHasErrors('images');
        $this->assertSame(0, $user->products()->count());
    }

    public function test_job_downloads_images_and_fills_fields_with_claude()
    {
        $this->jpegFake();
        config(['videoaff.script_driver' => 'claude']);
        $this->app->bind(ProductExtractor::class, fn () => new class implements ProductExtractor
        {
            public function extract(PageSnapshot $page): array
            {
                return ProductExtractPrompt::normalize(['name' => 'Chân váy lụa', 'sizes' => 'S, M, L', 'material' => 'Lụa', 'reviews' => '- Mát']);
            }
        });
        $product = Product::factory()->create(['import_status' => ProductImportStatus::Importing, 'import_snapshot' => $this->snapshot()]);

        ImportProductJob::dispatchSync($product);

        $product->refresh();
        $this->assertSame(ProductImportStatus::Done, $product->import_status);
        $this->assertNull($product->import_snapshot);
        $this->assertSame('Chân váy lụa', $product->name);
        $this->assertSame('S, M, L', $product->details['sizes']);
        $this->assertCount(2, $product->images);
        $this->assertSame(1, $product->images()->where('is_primary', true)->count());
    }

    public function test_web_mode_waits_for_claude_ai_reply()
    {
        $this->jpegFake();
        config(['videoaff.script_driver' => 'web']);
        $product = Product::factory()->create(['import_status' => ProductImportStatus::Importing, 'import_snapshot' => $this->snapshot()]);

        ImportProductJob::dispatchSync($product);
        $this->assertSame(ProductImportStatus::AwaitingReply, $product->fresh()->import_status);

        $this->actingAs($product->user)->get(route('products.edit', $product))
            ->assertInertia(fn (Assert $page) => $page->where('product.import_prompt', fn ($prompt) => str_contains($prompt, 'Chân váy lụa vân gỗ ₫259.000')));

        $this->actingAs($product->user)->post(route('products.import.reply', $product), ['reply' => 'xin lỗi'])
            ->assertSessionHasErrors(['reply' => 'Không tìm thấy JSON trong câu trả lời của Claude.']);

        $reply = "```json\n".json_encode(['name' => 'Váy', 'price' => '199.000đ', 'material' => 'Lụa'], JSON_UNESCAPED_UNICODE)."\n```";
        $this->actingAs($product->user)->post(route('products.import.reply', $product), ['reply' => $reply])->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertSame(ProductImportStatus::Done, $product->import_status);
        $this->assertSame(199000, $product->price);
        $this->assertSame('Lụa', $product->details['material']);
    }

    public function test_job_skips_non_images_and_keeps_max_five()
    {
        config(['videoaff.product_images_max' => 5]);
        $jpeg = UploadedFile::fake()->image('a.jpg')->getContent();
        Http::fake([
            '*/file/video' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html']),
            '*/file/broken' => Http::response('', 404),
            // CDN trả Content-Type chung chung nhưng nội dung là JPEG.
            '*' => Http::response($jpeg, 200, ['Content-Type' => 'application/octet-stream']),
        ]);
        $urls = array_map(fn ($id) => self::CDN.$id, ['a', 'video', 'broken', 'b', 'c', 'd', 'e', 'f']);
        $product = Product::factory()->create(['import_status' => ProductImportStatus::Importing, 'import_snapshot' => $this->snapshot(['images' => $urls])]);

        ImportProductJob::dispatchSync($product);

        $this->assertCount(5, $product->images()->get());
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/f'));
    }

    public function test_failed_download_marks_import_failed_and_can_retry()
    {
        Http::fake(['*' => Http::response('nope', 403)]);
        $product = Product::factory()->create(['import_status' => ProductImportStatus::Importing, 'import_snapshot' => $this->snapshot()]);

        ImportProductJob::dispatchSync($product);

        $product->refresh();
        $this->assertSame(ProductImportStatus::Failed, $product->import_status);
        $this->assertSame('Không tải được ảnh nào từ trang sản phẩm (HTTP 403).', $product->import_error);

        Queue::fake();
        $this->actingAs($product->user)->post(route('products.import.retry', $product))->assertRedirect();
        $this->assertSame(ProductImportStatus::Importing, $product->fresh()->import_status);
        Queue::assertPushed(ImportProductJob::class);
    }

    public function test_approving_keeps_selected_images_and_deletes_the_rest()
    {
        $product = Product::factory()->imagesNotApproved()->create();
        [$a, $b, $c] = collect(['a', 'b', 'c'])->map(function ($name) use ($product) {
            Storage::disk('public')->put("products/{$product->id}/{$name}.jpg", 'x');

            return ProductImage::factory()->for($product)->create(['path' => "products/{$product->id}/{$name}.jpg", 'is_primary' => $name === 'a']);
        })->all();

        $this->actingAs($product->user)
            ->post(route('products.images.approve', $product), ['image_ids' => [$b->id, $c->id], 'primary_id' => $c->id])
            ->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertTrue($product->imagesApproved());
        $this->assertSame([$b->id, $c->id], $product->images()->orderBy('id')->pluck('id')->all());
        $this->assertSame($c->id, $product->primaryImage->id);
        Storage::disk('public')->assertMissing($a->path);
        Storage::disk('public')->assertExists($b->path);
    }

    public function test_approve_validates_selection()
    {
        $product = Product::factory()->imagesNotApproved()->create();
        $mine = ProductImage::factory()->for($product)->create();
        $foreign = ProductImage::factory()->create();
        $user = $product->user;

        $this->actingAs($user)->post(route('products.images.approve', $product), ['image_ids' => [], 'primary_id' => $mine->id])
            ->assertSessionHasErrors('image_ids');
        $this->actingAs($user)->post(route('products.images.approve', $product), ['image_ids' => [$foreign->id], 'primary_id' => $foreign->id])
            ->assertSessionHasErrors('image_ids.0');
        $this->actingAs($user)->post(route('products.images.approve', $product), ['image_ids' => [$mine->id], 'primary_id' => $foreign->id])
            ->assertSessionHasErrors('primary_id');
        $this->actingAs(User::factory()->create())->post(route('products.images.approve', $product), ['image_ids' => [$mine->id], 'primary_id' => $mine->id])
            ->assertForbidden();

        $this->assertFalse($product->fresh()->imagesApproved());
        $this->assertTrue($foreign->fresh() !== null);
    }

    public function test_video_needs_approved_product_images()
    {
        $product = Product::factory()->imagesNotApproved()->create();
        $model = FashionModel::factory()->for($product->user)->create(['consent_at' => now()]);

        $this->actingAs($product->user)
            ->post(route('projects.store'), ['product_id' => $product->id, 'fashion_model_id' => $model->id])
            ->assertSessionHasErrors('product_id');
        $this->assertSame(0, $product->videoProjects()->count());
    }

    public function test_other_users_cannot_reply_or_retry()
    {
        $product = Product::factory()->create(['import_status' => ProductImportStatus::Failed, 'import_snapshot' => $this->snapshot()]);
        $other = User::factory()->create();

        $this->actingAs($other)->post(route('products.import.retry', $product))->assertForbidden();
        $this->actingAs($other)->post(route('products.import.reply', $product), ['reply' => '{}'])->assertForbidden();
    }

    public function test_normalize_handles_missing_and_bad_values()
    {
        $fields = ProductExtractPrompt::normalize(['name' => '  Áo  ', 'price' => 0]);

        $this->assertSame('Áo', $fields['name']);
        $this->assertNull($fields['price']);
        $this->assertSame('', $fields['sizes']);
    }

    public function test_only_shopee_cdn_hosts_are_allowed()
    {
        $this->assertTrue(RemoteImages::allowed(self::CDN.'a'));
        $this->assertFalse(RemoteImages::allowed('http://down-vn.img.susercontent.com/file/x'));
        $this->assertFalse(RemoteImages::allowed('https://evil.com/file/x'));
        $this->assertFalse(RemoteImages::allowed('https://susercontent.com.evil.com/x'));
        $this->assertFalse(RemoteImages::allowed('https://down-vn.img.susercontent.com:8443/x'));
    }

    public function test_import_from_tiktok_shop()
    {
        $this->jpegFake();
        $user = User::factory()->create();
        $cdn = 'https://p16-oec-sg.ibyteimg.com/tos-alisg-i-aphluv4xwc-sg/';

        $this->actingAs($user)->post(route('products.import'), $this->snapshot([
            'url' => 'https://shop.tiktok.com/vn/pdp/1735846581249148751',
            'title' => 'Đầm Dáng Xòe Phối Cổ Nơ Trắng - TikTok Shop Vietnam',
            'name' => '',
            'price' => 154000,
            'images' => [$cdn.'a~tplv-aphluv4xwc-origin-jpeg.jpeg', $cdn.'b~tplv-aphluv4xwc-origin-jpeg.jpeg', 'https://evil.com/x.jpg'],
        ]))->assertSessionHasNoErrors();

        $product = $user->products()->sole();
        $this->assertSame('Đầm Dáng Xòe Phối Cổ Nơ Trắng', $product->name);
        $this->assertSame(154000, $product->price);
        $this->assertSame('TikTok Shop', Product::shopName($product->shopee_url));
        $this->assertSame(ProductImportStatus::Done, $product->fresh()->import_status);
        $this->assertCount(2, $product->images);
        $this->assertTrue(RemoteImages::allowed('https://p19-oec-va.ibyteimg.com/x'));
        $this->assertTrue(RemoteImages::allowed('https://p16-common-sign.tiktokcdn.com/x'));
        $this->assertSame('Shopee', Product::shopName('https://shopee.vn/x'));
    }
}
