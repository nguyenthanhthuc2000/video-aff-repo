<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Models\VideoProject;
use App\Services\Script\ScriptPrompt;
use App\Services\Script\ScriptSchema;
use App\Support\SceneSettings;
use App\Support\VideoPreset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VideoSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_preset_recommendation_follows_product_richness()
    {
        $plain = Product::factory()->create(['price' => 99000, 'reviews' => 'Ổn.', 'details' => ['colors' => 'đen']]);
        $this->assertSame('short', VideoPreset::recommend($plain)['key']);

        $rich = Product::factory()->create(['price' => 450000, 'reviews' => str_repeat('Vải đẹp, form chuẩn. ', 20), 'details' => ['colors' => 'đen, trắng, be, hồng']]);
        ProductImage::factory()->count(6)->for($rich)->create();
        $recommendation = VideoPreset::recommend($rich);
        $this->assertSame('long', $recommendation['key']);
        $this->assertStringContainsString('nhiều màu', $recommendation['reason']);

        $options = VideoPreset::options($rich);
        $this->assertCount(4, $options);
        $this->assertSame(['long'], array_column(array_filter($options, fn ($o) => $o['recommended']), 'key'));
    }

    public function test_scene_suggestions_follow_product_name()
    {
        $this->assertContains('tiệc tối ở nhà hàng sang trọng', SceneSettings::suggestFor(Product::factory()->make(['name' => 'Đầm dự tiệc ren đen'])));
        $this->assertContains('văn phòng sáng sủa', SceneSettings::suggestFor(Product::factory()->make(['name' => 'Áo blazer công sở nữ'])));
        $this->assertContains('phòng ngủ buổi sáng nắng nhẹ', SceneSettings::suggestFor(Product::factory()->make(['name' => 'Bộ đồ ngủ lụa'])));
        $this->assertContains('đi dạo phố', SceneSettings::suggestFor(Product::factory()->make(['name' => 'Chân váy xếp ly'])));
        $this->assertNotEmpty(SceneSettings::suggestFor(Product::factory()->make(['name' => 'Túi xách da'])));
    }

    public function test_prompt_includes_scene_count_duration_and_settings()
    {
        VideoPreset::saveSettings(['mode' => 'fixed', 'scene_count' => 5, 'target_seconds' => 20]);
        $product = Product::factory()->create(['name' => 'Đầm dự tiệc ren đen']);
        $project = VideoProject::factory()->for($product)->create();

        $text = ScriptPrompt::userText($project);

        $this->assertStringContainsString('đúng 5 cảnh, tổng khoảng 20 giây', $text);
        $this->assertStringContainsString('sảnh khách sạn', $text);
        $this->assertStringContainsString('đúng 5 câu mở đầu', ScriptPrompt::SYSTEM);
        $this->assertContains('recommended_hook', ScriptSchema::schema()['required']);
    }

    public function test_auto_mode_uses_product_recommendation_for_every_project()
    {
        $plain = Product::factory()->create(['price' => 99000, 'reviews' => 'Ổn.', 'details' => ['colors' => 'đen']]);
        $project = VideoProject::factory()->for($plain)->create();

        $this->assertSame('auto', VideoPreset::settings()['mode']);
        $settings = $project->videoSettings();
        $this->assertSame([4, 15, 'short'], [$settings['scenes'], $settings['seconds'], $settings['preset']]);
        $this->assertStringStartsWith('Đề xuất theo sản phẩm', $settings['reason']);
    }

    public function test_settings_menu_saves_system_wide_length()
    {
        $user = User::factory()->admin()->create();
        $project = VideoProject::factory()->create();

        $this->actingAs($user)->get(route('video-settings.show'))->assertInertia(fn (Assert $page) => $page
            ->component('video-settings/index')
            ->where('settings.mode', 'auto')
            ->has('presets', 4));

        $this->actingAs($user)->put(route('video-settings.update'), ['mode' => 'fixed', 'scene_count' => 8, 'target_seconds' => 40])->assertSessionHasNoErrors();
        $this->assertSame(['scenes' => 8, 'seconds' => 40], array_intersect_key($project->fresh()->videoSettings(), ['scenes' => 0, 'seconds' => 0]));

        $this->actingAs($user)->put(route('video-settings.update'), ['mode' => 'fixed', 'scene_count' => 10, 'target_seconds' => 12])->assertSessionHasErrors('target_seconds');
        $this->actingAs($user)->put(route('video-settings.update'), ['mode' => 'fixed', 'scene_count' => 20, 'target_seconds' => 40])->assertSessionHasErrors('scene_count');
        $this->actingAs($user)->put(route('video-settings.update'), ['mode' => 'other', 'scene_count' => 6, 'target_seconds' => 25])->assertSessionHasErrors('mode');

        $this->actingAs($project->user)->get(route('projects.show', $project))->assertInertia(fn (Assert $page) => $page
            ->where('project.video_settings.scenes', 8)
            ->where('project.video_settings.mode', 'fixed')
            ->has('project.scene_suggestions'));
    }
}
