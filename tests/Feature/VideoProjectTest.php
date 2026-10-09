<?php

namespace Tests\Feature;

use App\Models\FashionModel;
use App\Models\Product;
use App\Models\User;
use App\Models\VideoProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VideoProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_creates_project_from_own_product_and_consenting_model()
    {
        $user = User::factory()->create();
        $product = Product::factory()->for($user)->create();
        $model = FashionModel::factory()->for($user)->create();

        $response = $this->actingAs($user)->post('/projects', [
            'product_id' => $product->id,
            'fashion_model_id' => $model->id,
        ]);

        $project = $user->videoProjects()->first();
        $response->assertRedirect(route('projects.show', $project));
        $this->assertSame('draft', $project->status->value);
    }

    public function test_cannot_use_other_users_records_or_model_without_consent()
    {
        $user = User::factory()->create();
        $product = Product::factory()->for($user)->create();

        $this->actingAs($user)->post('/projects', [
            'product_id' => Product::factory()->create()->id,
            'fashion_model_id' => FashionModel::factory()->for($user)->create()->id,
        ])->assertSessionHasErrors('product_id');

        $this->actingAs($user)->post('/projects', [
            'product_id' => $product->id,
            'fashion_model_id' => FashionModel::factory()->create()->id,
        ])->assertSessionHasErrors('fashion_model_id');

        $this->actingAs($user)->post('/projects', [
            'product_id' => $product->id,
            'fashion_model_id' => FashionModel::factory()->for($user)->withoutConsent()->create()->id,
        ])->assertSessionHasErrors('fashion_model_id');

        $this->assertSame(0, $user->videoProjects()->count());
    }

    public function test_show_and_index()
    {
        $project = VideoProject::factory()->create();

        $this->actingAs($project->user)->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/show')
                ->where('project.status', 'draft')
                ->where('project.is_busy', false)
                ->has('project.scenes', 0));

        $this->actingAs($project->user)->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page->component('projects/index')->has('projects', 1));
    }

    public function test_other_users_cannot_view_or_delete()
    {
        $project = VideoProject::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($other)->get(route('projects.show', $project))->assertForbidden();
        $this->actingAs($other)->delete(route('projects.destroy', $project))->assertForbidden();

        $this->actingAs($project->user)->delete(route('projects.destroy', $project))->assertRedirect(route('projects.index'));
        $this->assertModelMissing($project);
    }
}
