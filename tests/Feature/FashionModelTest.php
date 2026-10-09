<?php

namespace Tests\Feature;

use App\Models\FashionModel;
use App\Models\User;
use App\Models\VideoProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FashionModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_index_lists_own_models()
    {
        $user = User::factory()->create();
        FashionModel::factory()->for($user)->create();
        FashionModel::factory()->create();

        $this->actingAs($user)->get('/fashion-models')
            ->assertInertia(fn (Assert $page) => $page->component('fashion-models/index')->has('fashionModels', 1));
    }

    public function test_adding_a_model_requires_consent()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/fashion-models', [
            'name' => 'Lan',
            'photo' => UploadedFile::fake()->image('lan.jpg'),
        ])->assertSessionHasErrors('consent');

        $this->actingAs($user)->post('/fashion-models', [
            'name' => 'Lan',
            'photo' => UploadedFile::fake()->image('lan.jpg'),
            'consent' => true,
        ])->assertSessionHasNoErrors();

        $model = $user->fashionModels()->first();
        $this->assertTrue($model->hasConsent());
        Storage::disk('public')->assertExists($model->photo_path);
    }

    public function test_model_in_use_cannot_be_deleted()
    {
        $project = VideoProject::factory()->create();
        $model = $project->fashionModel;

        $this->actingAs($project->user)->delete(route('fashion-models.destroy', $model))
            ->assertSessionHasErrors('fashion_model');
        $this->assertModelExists($model);
    }

    public function test_owner_can_delete_unused_model_and_others_cannot()
    {
        $model = FashionModel::factory()->create();

        $this->actingAs(User::factory()->create())->delete(route('fashion-models.destroy', $model))->assertForbidden();
        $this->actingAs($model->user)->delete(route('fashion-models.destroy', $model));
        $this->assertModelMissing($model);
    }
}
