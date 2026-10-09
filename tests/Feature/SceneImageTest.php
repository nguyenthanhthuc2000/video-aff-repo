<?php

namespace Tests\Feature;

use App\Enums\SceneImageStatus;
use App\Enums\VideoProjectStatus;
use App\Jobs\GenerateSceneImageJob;
use App\Models\Scene;
use App\Models\SceneImage;
use App\Models\User;
use App\Models\VideoProject;
use App\Services\Image\FalMuseImageGenerator;
use App\Services\Image\GeneratedImage;
use App\Services\Image\ImageGenerator;
use App\Services\Image\ImageGeneratorException;
use App\Support\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SceneImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function projectWithScenes(int $scenes = 2): VideoProject
    {
        $project = VideoProject::factory()->create(['status' => VideoProjectStatus::ScriptReady, 'script' => ['hooks' => ['h']]]);
        Media::disk()->put($project->fashionModel->photo_path, 'model-photo');
        $project->product->images()->create(['path' => 'products/a.jpg', 'is_primary' => true]);
        Media::disk()->put('products/a.jpg', 'product-photo');
        Scene::factory()->count($scenes)->for($project)->sequence(fn ($s) => ['position' => $s->index + 1])->create();

        return $project;
    }

    public function test_generate_all_creates_images_and_moves_to_reviewing()
    {
        $project = $this->projectWithScenes();

        $this->actingAs($project->user)->post(route('projects.images.store', $project))->assertRedirect();

        $project->refresh();
        $this->assertSame(VideoProjectStatus::Reviewing, $project->status);
        foreach ($project->scenes as $scene) {
            $image = $scene->latestImage;
            $this->assertSame(SceneImageStatus::Ready, $image->status);
            $this->assertSame('model-photo', Media::disk()->get($image->path));
        }
    }

    public function test_generate_all_queues_one_job_per_scene_and_skips_scenes_with_images()
    {
        Queue::fake();
        $project = $this->projectWithScenes(3);
        SceneImage::factory()->for($project->scenes[0])->create();

        $this->actingAs($project->user)->post(route('projects.images.store', $project));

        $this->assertSame(VideoProjectStatus::Imaging, $project->fresh()->status);
        Queue::assertPushed(GenerateSceneImageJob::class, 2);
    }

    public function test_generate_requires_script()
    {
        Queue::fake();
        $project = VideoProject::factory()->create();

        $this->actingAs($project->user)->post(route('projects.images.store', $project));

        Queue::assertNothingPushed();
        $this->assertSame(VideoProjectStatus::Draft, $project->fresh()->status);
    }

    public function test_job_sends_model_and_product_images_with_prompt()
    {
        $project = $this->projectWithScenes(1);
        $spy = new class implements ImageGenerator
        {
            public array $calls = [];

            public function generate(array $referencePaths, string $prompt): GeneratedImage
            {
                $this->calls[] = [$referencePaths, $prompt];

                return new GeneratedImage('img', 'jpg', 'spy', 'req-1', 0.01);
            }
        };
        $this->app->instance(ImageGenerator::class, $spy);

        $this->actingAs($project->user)->post(route('projects.images.store', $project));

        [$refs, $prompt] = $spy->calls[0];
        $this->assertSame([$project->fashionModel->photo_path, 'products/a.jpg'], $refs);
        $this->assertStringStartsWith($project->scenes[0]->image_prompt, $prompt);
        $image = $project->scenes[0]->latestImage;
        $this->assertSame('req-1', $image->provider_request_id);
        $this->assertSame('0.0100', $image->cost_usd);
    }

    public function test_failed_generation_marks_image_failed_and_project_reviewing()
    {
        $this->app->bind(ImageGenerator::class, fn () => new class implements ImageGenerator
        {
            public function generate(array $referencePaths, string $prompt): GeneratedImage
            {
                throw new ImageGeneratorException('Khoá fal không hợp lệ (kiểm tra FAL_KEY).');
            }
        });
        $project = $this->projectWithScenes(1);

        $this->actingAs($project->user)->post(route('projects.images.store', $project));

        $image = $project->scenes[0]->latestImage;
        $this->assertSame(SceneImageStatus::Failed, $image->status);
        $this->assertSame('Khoá fal không hợp lệ (kiểm tra FAL_KEY).', $image->error);
        $this->assertSame(VideoProjectStatus::Reviewing, $project->fresh()->status);
    }

    public function test_approve_and_regenerate()
    {
        $project = $this->projectWithScenes(1);
        $this->actingAs($project->user)->post(route('projects.images.store', $project));
        $scene = $project->scenes[0];
        $first = $scene->latestImage;

        $this->post(route('scene-images.approve', $first));
        $this->assertSame(SceneImageStatus::Approved, $first->fresh()->status);
        $this->assertTrue($project->allScenesApproved());

        $this->post(route('scenes.image.regenerate', $scene), ['image_prompt' => 'new prompt']);

        $this->assertSame(SceneImageStatus::Rejected, $first->fresh()->status);
        $this->assertSame('new prompt', $scene->fresh()->image_prompt);
        $this->assertSame(SceneImageStatus::Ready, $scene->fresh()->latestImage->status);
        $this->assertFalse($project->allScenesApproved());
    }

    public function test_other_users_cannot_touch_images()
    {
        $project = $this->projectWithScenes(1);
        $image = SceneImage::factory()->for($project->scenes[0])->create();
        $other = User::factory()->create();

        $this->actingAs($other)->post(route('projects.images.store', $project))->assertForbidden();
        $this->actingAs($other)->post(route('scenes.image.regenerate', $project->scenes[0]))->assertForbidden();
        $this->actingAs($other)->post(route('scene-images.approve', $image))->assertForbidden();
    }

    public function test_fal_generator_posts_data_urls_and_downloads_result()
    {
        Media::disk()->put('models/m.jpg', 'model');
        Http::fake([
            'fal.run/*' => Http::response(['images' => [['url' => 'https://v3.fal.media/files/out.jpg']]], 200, ['x-fal-request-id' => 'abc']),
            'v3.fal.media/*' => Http::response('jpeg-bytes'),
        ]);

        $result = (new FalMuseImageGenerator('secret', 'meta/muse-image/edit', 0.01))->generate(['models/m.jpg'], 'prompt');

        $this->assertSame('jpeg-bytes', $result->contents);
        $this->assertSame('abc', $result->requestId);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://fal.run/meta/muse-image/edit'
            && $request->hasHeader('Authorization', 'Key secret')
            && $request['aspect_ratio'] === '9:16'
            && str_starts_with($request['image_urls'][0], 'data:'));
    }

    public function test_fal_generator_maps_errors()
    {
        Media::disk()->put('models/m.jpg', 'model');
        Http::fake(['fal.run/*' => Http::sequence()->push(['detail' => 'bad'], 401)->push([], 503)]);
        $generator = new FalMuseImageGenerator('secret', 'meta/muse-image/edit', 0.01);

        try {
            $generator->generate(['models/m.jpg'], 'p');
            $this->fail('Expected exception');
        } catch (ImageGeneratorException $e) {
            $this->assertFalse($e->retryable);
        }

        try {
            $generator->generate(['models/m.jpg'], 'p');
            $this->fail('Expected exception');
        } catch (ImageGeneratorException $e) {
            $this->assertTrue($e->retryable);
        }
    }
}
