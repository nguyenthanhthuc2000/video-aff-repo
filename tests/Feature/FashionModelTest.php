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
            'photos' => [UploadedFile::fake()->image('lan.jpg')],
        ])->assertSessionHasErrors('consent');

        $this->actingAs($user)->post('/fashion-models', [
            'name' => 'Lan',
            'photos' => [UploadedFile::fake()->image('lan.jpg')],
            'consent' => true,
        ])->assertSessionHasNoErrors();

        $model = $user->fashionModels()->first();
        $this->assertTrue($model->hasConsent());
        Storage::disk('public')->assertExists($model->photo_path);
    }

    public function test_upload_many_photos_with_default_names()
    {
        $user = User::factory()->create();
        $user->fashionModels()->create(['name' => 'Mẫu 2', 'photo_path' => 'models/old.jpg', 'consent_at' => now()]);

        $this->actingAs($user)->post('/fashion-models', [
            'photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg'), UploadedFile::fake()->image('c.jpg')],
            'consent' => true,
        ])->assertSessionHas('status', 'Đã thêm 3 người mẫu.');

        $this->assertSame(['Mẫu 2', 'Mẫu 3', 'Mẫu 4', 'Mẫu 5'], $user->fashionModels()->orderBy('id')->pluck('name')->all());
        $user->fashionModels()->where('name', '!=', 'Mẫu 2')->get()->each(function ($model) {
            $this->assertTrue($model->hasConsent());
            Storage::disk('public')->assertExists($model->photo_path);
        });

        $this->actingAs($user)->post('/fashion-models', [
            'name' => 'Lan',
            'photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
            'consent' => true,
        ]);
        $this->assertSame(['Lan 1', 'Lan 2'], $user->fashionModels()->where('name', 'like', 'Lan%')->orderBy('id')->pluck('name')->all());

        $this->actingAs($user)->post('/fashion-models', [
            'photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')],
            'consent' => true,
        ])->assertSessionHasErrors('photos.1');
        $this->assertSame(6, $user->fashionModels()->count());
    }

    public function test_before_and_after_kinds_have_own_default_names_and_can_be_switched()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/fashion-models', ['photos' => [UploadedFile::fake()->image('a.jpg')], 'consent' => true]);
        $this->actingAs($user)->post('/fashion-models', [
            'kind' => 'before',
            'photos' => [UploadedFile::fake()->image('b.jpg'), UploadedFile::fake()->image('c.jpg')],
            'consent' => true,
        ]);

        $models = $user->fashionModels()->orderBy('id')->get();
        $this->assertSame(['Mẫu 1', 'Mẫu trước 1', 'Mẫu trước 2'], $models->pluck('name')->all());
        $this->assertSame(['after', 'before', 'before'], $models->map(fn ($m) => $m->kind->value)->all());

        $this->actingAs($user)->put(route('fashion-models.update', $models[0]), ['kind' => 'before'])->assertSessionHasNoErrors();
        $this->assertSame('before', $models[0]->fresh()->kind->value);
        $this->actingAs($user)->put(route('fashion-models.update', $models[0]), ['kind' => 'sideways'])->assertSessionHasErrors('kind');
        $this->actingAs(User::factory()->create())->put(route('fashion-models.update', $models[0]), ['kind' => 'after'])->assertForbidden();

        $this->actingAs($user)->get('/fashion-models')->assertInertia(fn (Assert $page) => $page->where('fashionModels.0.kind', fn ($kind) => in_array($kind, ['after', 'before'], true)));
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
