<?php

namespace Tests\Feature;

use App\Enums\SceneImageStatus;
use App\Enums\ScenePhase;
use App\Enums\VideoProjectStatus;
use App\Enums\VideoType;
use App\Jobs\GenerateSceneImageJob;
use App\Models\FashionModel;
use App\Models\Product;
use App\Models\Scene;
use App\Models\User;
use App\Models\VideoProject;
use App\Services\Image\GeneratedImage;
use App\Services\Image\ImageGenerator;
use App\Services\Script\ScriptPrompt;
use App\Support\VideoPreset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TransformationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function transformationProject(): VideoProject
    {
        $project = VideoProject::factory()->create();
        $before = FashionModel::factory()->for($project->user)->create(['name' => 'Mộc', 'kind' => 'before']);
        $project->update(['video_type' => VideoType::Transformation, 'before_fashion_model_id' => $before->id]);

        return $project->fresh();
    }

    public function test_project_can_be_created_as_transformation_with_two_models()
    {
        $user = User::factory()->create();
        $product = Product::factory()->for($user)->create();
        $after = FashionModel::factory()->for($user)->create();
        $before = FashionModel::factory()->for($user)->create(['kind' => 'before']);

        $this->actingAs($user)->post(route('projects.store'), [
            'product_id' => $product->id,
            'fashion_model_id' => $after->id,
            'video_type' => 'transformation',
        ])->assertSessionHasErrors('before_fashion_model_id');

        $other = FashionModel::factory()->create(['kind' => 'before']);
        $this->actingAs($user)->post(route('projects.store'), [
            'product_id' => $product->id,
            'fashion_model_id' => $after->id,
            'video_type' => 'transformation',
            'before_fashion_model_id' => $other->id,
        ])->assertSessionHasErrors('before_fashion_model_id');

        $this->actingAs($user)->post(route('projects.store'), [
            'product_id' => $product->id,
            'fashion_model_id' => $after->id,
            'video_type' => 'transformation',
            'before_fashion_model_id' => $before->id,
        ])->assertSessionHasNoErrors();

        // Loại phải đúng: ảnh "sau" không dùng làm ảnh "trước" và ngược lại.
        $this->actingAs($user)->post(route('projects.store'), [
            'product_id' => $product->id,
            'fashion_model_id' => $before->id,
            'video_type' => 'transformation',
            'before_fashion_model_id' => $after->id,
        ])->assertSessionHasErrors(['fashion_model_id', 'before_fashion_model_id']);

        $project = $user->videoProjects()->sole();
        $this->assertTrue($project->isTransformation());
        $this->assertTrue($project->beforeFashionModel->is($before));

        // Video thường bỏ ảnh "trước" dù có gửi lên.
        $this->actingAs($user)->post(route('projects.store'), [
            'product_id' => $product->id,
            'fashion_model_id' => $after->id,
            'before_fashion_model_id' => $before->id,
        ]);
        $standard = $user->videoProjects()->latest('id')->first();
        $this->assertSame(VideoType::Standard, $standard->video_type);
        $this->assertNull($standard->before_fashion_model_id);
    }

    public function test_script_marks_before_scenes_and_prompt_explains_transformation()
    {
        VideoPreset::saveSettings(['mode' => 'fixed', 'scene_count' => 4, 'target_seconds' => 16]);
        $project = $this->transformationProject();

        $this->assertStringContainsString('Video biến hình', ScriptPrompt::userText($project));

        $this->actingAs($project->user)->post(route('projects.script.store', $project));

        $phases = $project->scenes()->pluck('phase')->map->value->all();
        $this->assertSame(['before', 'after', 'after', 'after'], $phases);

        $standard = VideoProject::factory()->create();
        $this->assertStringNotContainsString('Video biến hình', ScriptPrompt::userText($standard));
        $this->actingAs($standard->user)->post(route('projects.script.store', $standard));
        $this->assertSame([null], $standard->scenes()->pluck('phase')->unique()->values()->all());
    }

    public function test_before_scene_uses_only_before_photo_without_product()
    {
        $project = $this->transformationProject();
        $project->product->images()->create(['path' => 'products/p.jpg', 'is_primary' => true]);
        $before = Scene::factory()->for($project)->create(['position' => 1, 'phase' => ScenePhase::Before, 'image_prompt' => 'Messy bedroom morning.']);
        $after = Scene::factory()->for($project)->create(['position' => 2, 'phase' => ScenePhase::After]);

        $calls = [];
        $this->app->instance(ImageGenerator::class, new class($calls) implements ImageGenerator
        {
            public function __construct(public array &$calls) {}

            public function generate(array $referencePaths, string $prompt): GeneratedImage
            {
                $this->calls[] = [$referencePaths, $prompt];

                return new GeneratedImage('img', 'jpg', 'test');
            }
        });

        GenerateSceneImageJob::dispatchSync($before->images()->create());
        GenerateSceneImageJob::dispatchSync($after->images()->create());

        [$beforeRefs, $beforePrompt] = $calls[0];
        $this->assertSame([$project->beforeFashionModel->photo_path], $beforeRefs);
        $this->assertStringContainsString('The clothes must stay EXACTLY the same as in the attached photo', $beforePrompt);
        $this->assertStringContainsString('ignore any clothing described above', $beforePrompt);
        [$afterRefs, $afterPrompt] = $calls[1];
        $this->assertSame([$project->fashionModel->photo_path, 'products/p.jpg'], $afterRefs);
        $this->assertStringContainsString('wearing the exact garment', $afterPrompt);
        $this->assertSame(SceneImageStatus::Ready, $before->latestImage->status);
    }

    public function test_before_model_in_use_cannot_be_deleted()
    {
        $project = $this->transformationProject();

        $this->actingAs($project->user)->delete(route('fashion-models.destroy', $project->beforeFashionModel))->assertSessionHasErrors('fashion_model');
        $this->assertNotNull($project->fresh()->beforeFashionModel);
    }

    public function test_projects_index_filters_by_status_with_counts()
    {
        $user = User::factory()->create();
        VideoProject::factory()->for($user)->count(2)->create(['status' => VideoProjectStatus::Done]);
        VideoProject::factory()->for($user)->create(['status' => VideoProjectStatus::Failed]);
        VideoProject::factory()->create(['status' => VideoProjectStatus::Done]);

        $this->actingAs($user)->get(route('projects.index', ['status' => 'done']))->assertInertia(fn (Assert $page) => $page
            ->has('projects', 2)
            ->where('filters.status', 'done')
            ->where('statusCounts.done', 2)
            ->where('statusCounts.failed', 1)
            ->where('total', 3));

        $this->actingAs($user)->get(route('projects.index', ['status' => 'bogus']))->assertInertia(fn (Assert $page) => $page
            ->has('projects', 3)
            ->where('filters.status', null));
    }

    public function test_projects_index_includes_latest_finished_video_for_quick_preview()
    {
        $user = User::factory()->create();
        $done = VideoProject::factory()->for($user)->create(['status' => VideoProjectStatus::Done, 'script' => ['hooks' => ['Hook A', 'Hook B']]]);
        $done->renders()->create(['hook' => 0, 'status' => 'done', 'path' => 'renders/1.mp4', 'duration_ms' => 20000]);
        $newest = $done->renders()->create(['hook' => 1, 'status' => 'done', 'path' => 'renders/2.mp4', 'duration_ms' => 25000]);
        $done->renders()->create(['hook' => 0, 'status' => 'failed']);
        $draft = VideoProject::factory()->for($user)->create(['status' => VideoProjectStatus::Draft]);

        $this->actingAs($user)->get(route('projects.index'))->assertInertia(fn (Assert $page) => $page->where('projects', function ($projects) use ($done, $draft, $newest) {
            $byId = collect($projects)->keyBy('id');

            return $byId[$draft->id]['latest_render'] === null
                && $byId[$done->id]['latest_render']['id'] === $newest->id
                && $byId[$done->id]['latest_render']['hook'] === 'Hook B'
                && $byId[$done->id]['latest_render']['download_url'] === route('renders.download', $newest);
        }));
    }
}
