<?php

namespace Tests\Feature;

use App\Enums\SceneImageStatus;
use App\Enums\VideoProjectStatus;
use App\Models\FashionModel;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Scene;
use App\Models\SceneImage;
use App\Models\User;
use App\Models\VideoProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_video_project_belongs_to_one_owner_with_product_and_model()
    {
        $project = VideoProject::factory()->create();

        $this->assertSame(VideoProjectStatus::Draft, $project->status);
        $this->assertSame($project->user_id, $project->product->user_id);
        $this->assertSame($project->user_id, $project->fashionModel->user_id);
    }

    public function test_primary_image_prefers_flagged_image()
    {
        $product = Product::factory()->create();
        ProductImage::factory()->for($product)->create();
        $primary = ProductImage::factory()->for($product)->primary()->create();

        $this->assertTrue($product->primaryImage->is($primary));
    }

    public function test_primary_image_falls_back_to_first_image()
    {
        $product = Product::factory()->create();
        $first = ProductImage::factory()->for($product)->create();
        ProductImage::factory()->for($product)->create();

        $this->assertTrue($product->primaryImage->is($first));
    }

    public function test_scene_tracks_latest_and_approved_images()
    {
        $scene = Scene::factory()->create();
        $approved = SceneImage::factory()->for($scene)->approved()->create();
        $latest = SceneImage::factory()->for($scene)->create(['status' => SceneImageStatus::Rejected]);

        $this->assertTrue($scene->latestImage->is($latest));
        $this->assertTrue($scene->approvedImage->is($approved));
    }

    public function test_fashion_model_consent()
    {
        $this->assertTrue(FashionModel::factory()->create()->hasConsent());
        $this->assertFalse(FashionModel::factory()->withoutConsent()->create()->hasConsent());
    }

    public function test_owners_only_can_access_their_records()
    {
        $project = VideoProject::factory()->create();
        $owner = $project->user;
        $other = User::factory()->create();

        $this->assertTrue($owner->can('view', $project));
        $this->assertTrue($owner->can('update', $project->product));
        $this->assertFalse($other->can('view', $project));
        $this->assertFalse($other->can('update', $project->product));
        $this->assertFalse($other->can('delete', $project->fashionModel));
    }
}
