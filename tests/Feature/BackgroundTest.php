<?php

namespace Tests\Feature;

use App\Enums\SceneImageStatus;
use App\Jobs\GenerateSceneImageJob;
use App\Models\Background;
use App\Models\ProductImage;
use App\Models\Scene;
use App\Models\SceneImage;
use App\Models\User;
use App\Models\VideoProject;
use App\Services\Image\ImageGenerator;
use App\Services\Image\Prompt\ScenePromptRewriter;
use App\Services\Script\ScriptPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BackgroundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function script(int $scenes): array
    {
        return [
            'hooks' => ['H1', 'H2', 'H3', 'H4', 'H5'],
            'scenes' => array_map(fn ($i) => ['visual' => "V{$i}", 'on_screen_text' => 'T', 'voiceover' => 'L', 'image_prompt' => "P{$i}", 'duration_ms' => 3000], range(1, $scenes)),
            'caption' => 'C',
            'hashtags' => ['#a'],
        ];
    }

    public function test_user_uploads_backgrounds_with_default_names_and_manages_them()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('backgrounds.store'), [
            'photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
            'description' => 'gương lớn',
        ])->assertSessionHasNoErrors();

        $this->assertSame(['Bối cảnh 1', 'Bối cảnh 2'], $user->backgrounds()->orderBy('id')->pluck('name')->all());
        $background = $user->backgrounds()->first();
        Storage::disk('public')->assertExists($background->photo_path);

        $this->actingAs($user)->put(route('backgrounds.update', $background), ['name' => 'Phòng thay đồ', 'description' => 'rèm be'])->assertSessionHasNoErrors();
        $this->assertSame('Phòng thay đồ (rèm be)', $background->fresh()->label());

        $this->actingAs($user)->get(route('backgrounds.index'))->assertInertia(fn (Assert $page) => $page->component('backgrounds/index')->has('backgrounds', 2));

        // Người khác không sửa / xoá được.
        $other = User::factory()->create();
        $this->actingAs($other)->put(route('backgrounds.update', $background), ['name' => 'x'])->assertForbidden();
        $this->actingAs($other)->delete(route('backgrounds.destroy', $background))->assertForbidden();

        // Xoá: cảnh đang dùng chuyển về AI tự nghĩ.
        $scene = Scene::factory()->create(['background_id' => $background->id]);
        $this->actingAs($user)->delete(route('backgrounds.destroy', $background))->assertSessionHasNoErrors();
        $this->assertNull($scene->fresh()->background_id);
        Storage::disk('public')->assertMissing($background->photo_path);
    }

    public function test_project_uses_own_backgrounds_in_order_for_script_and_scenes()
    {
        $project = VideoProject::factory()->create();
        $user = $project->user;
        $project->product->update(['images_approved_at' => now()]);
        $room = Background::factory()->for($user)->create(['name' => 'Phòng thay đồ', 'description' => 'gương lớn']);
        $shop = Background::factory()->for($user)->create(['name' => 'Góc shop', 'description' => null]);
        $foreign = Background::factory()->create();

        $this->actingAs($user)->post(route('projects.store'), [
            'product_id' => $project->product_id,
            'fashion_model_id' => $project->fashion_model_id,
            'background_ids' => [$foreign->id],
        ])->assertSessionHasErrors('background_ids.0');

        $this->actingAs($user)->post(route('projects.store'), [
            'product_id' => $project->product_id,
            'fashion_model_id' => $project->fashion_model_id,
            'background_ids' => [$shop->id, $room->id],
        ])->assertSessionHasNoErrors();
        $created = $user->videoProjects()->latest('id')->first();
        $this->assertSame([$shop->id, $room->id], $created->background_ids);

        $text = ScriptPrompt::userText($created);
        $this->assertStringContainsString('Cảnh 1: Góc shop', $text);
        $this->assertStringContainsString('Cảnh 2: Phòng thay đồ (gương lớn)', $text);
        $this->assertStringNotContainsString('Bối cảnh hợp với sản phẩm', $text);

        $created->applyScript($this->script(3));
        $this->assertSame([$shop->id, $room->id, $shop->id], $created->scenes()->pluck('background_id')->all());

        // AI tự đề xuất: không gửi bối cảnh thì null, prompt gợi ý theo sản phẩm.
        $this->actingAs($user)->post(route('projects.store'), [
            'product_id' => $project->product_id,
            'fashion_model_id' => $project->fashion_model_id,
            'background_ids' => [],
        ])->assertSessionHasNoErrors();
        $ai = $user->videoProjects()->latest('id')->first();
        $this->assertNull($ai->background_ids);
        $this->assertStringContainsString('Bối cảnh hợp với sản phẩm', ScriptPrompt::userText($ai));
        $ai->applyScript($this->script(2));
        $this->assertSame([null, null], $ai->scenes()->pluck('background_id')->all());
    }

    public function test_background_photo_is_sent_second_with_placement_instruction()
    {
        config(['videoaff.image_driver' => 'browser', 'videoaff.muse_browser.url' => 'http://browser:3000']);
        $image = SceneImage::factory()->create(['status' => SceneImageStatus::Pending]);
        $scene = $image->scene;
        $project = $scene->videoProject;
        $background = Background::factory()->for($project->user)->create();
        $scene->update(['background_id' => $background->id]);
        Storage::disk('public')->put($project->fashionModel->photo_path, 'model-bytes');
        Storage::disk('public')->put($background->photo_path, 'background-bytes');
        foreach (range(1, 3) as $i) {
            $product = ProductImage::factory()->for($project->product)->create(['path' => "products/p{$i}.jpg", 'is_primary' => $i === 1]);
            Storage::disk('public')->put($product->path, "product-{$i}");
        }
        Http::fake(['http://browser:3000/tasks' => Http::response(['state' => 'pending'])]);

        $job = (new GenerateSceneImageJob($image))->withFakeQueueInteractions();
        $job->handle(app(ImageGenerator::class), app(ScenePromptRewriter::class));

        Http::assertSent(fn (Request $request) => $request->url() === 'http://browser:3000/tasks'
            && count($request['images']) === 4
            && $request['images'][0]['base64'] === base64_encode('model-bytes')
            && $request['images'][1]['base64'] === base64_encode('background-bytes')
            && $request['images'][2]['base64'] === base64_encode('product-1')
            && str_contains($request['prompt'], 'the second attached image is the background location')
            && str_contains($request['prompt'], GenerateSceneImageJob::BACKGROUND));
    }

    public function test_scene_background_can_be_changed_by_owner_only()
    {
        $scene = Scene::factory()->create();
        $user = $scene->videoProject->user;
        $background = Background::factory()->for($user)->create();

        $this->actingAs($user)->put(route('scenes.background', $scene), ['background_id' => $background->id])->assertSessionHasNoErrors();
        $this->assertSame($background->id, $scene->fresh()->background_id);

        $this->actingAs($user)->put(route('scenes.background', $scene), ['background_id' => Background::factory()->create()->id])->assertSessionHasErrors('background_id');
        $this->actingAs(User::factory()->create())->put(route('scenes.background', $scene), ['background_id' => null])->assertForbidden();

        $this->actingAs($user)->put(route('scenes.background', $scene), ['background_id' => null])->assertSessionHasNoErrors();
        $this->assertNull($scene->fresh()->background_id);
    }
}
