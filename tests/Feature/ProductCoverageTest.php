<?php

namespace Tests\Feature;

use App\Enums\MotionStyle;
use App\Jobs\GenerateSceneImageJob;
use App\Models\Product;
use App\Models\SampleVideo;
use App\Models\Scene;
use App\Models\User;
use App\Models\VideoProject;
use App\Services\Script\ScriptPrompt;
use App\Services\Video\FfmpegVideoRenderer;
use App\Services\Video\RenderScene;
use App\Services\Video\RenderSpec;
use App\Support\Framing;
use App\Support\ProductCoverage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function dressProject(): VideoProject
    {
        return VideoProject::factory()->for(Product::factory()->create(['name' => 'Váy chấm bi cổ sen đính nơ']))->create();
    }

    private function script(int $count): array
    {
        return [
            'hooks' => ['a', 'b', 'c', 'd', 'e'],
            'caption' => 'c',
            'hashtags' => ['#a'],
            'scenes' => array_map(fn ($i) => ['visual' => "v{$i}", 'on_screen_text' => 't', 'voiceover' => 'l', 'image_prompt' => "p{$i}", 'duration_ms' => 3000], range(1, $count)),
        ];
    }

    public function test_plan_covers_product_angles_and_skips_sample_videos()
    {
        $project = $this->dressProject();
        $this->assertSame('clothing', ProductCoverage::kind($project));
        $this->assertSame(['front_full', 'top_detail', 'fabric', 'back', 'styling'], ProductCoverage::plan($project, 5));
        $this->assertCount(8, array_unique(ProductCoverage::plan($project, 8)));

        $project->update(['sample_video_id' => SampleVideo::factory()->for($project->user)->create()->id]);
        $this->assertSame([null, null], ProductCoverage::plan($project->fresh(), 2));
    }

    public function test_script_prompt_and_scenes_get_angles()
    {
        $project = $this->dressProject();
        $text = ScriptPrompt::userText($project);
        $this->assertStringContainsString('Góc quay sản phẩm', $text);
        $this->assertStringContainsString('Toàn thân, vuốt chất vải', $text);
        $this->assertStringContainsString('MỘT buổi quay thật', ScriptPrompt::SYSTEM);

        $project->applyScript($this->script(5));
        $this->assertSame(['front_full', 'top_detail', 'fabric', 'back', 'styling'], $project->scenes()->orderBy('position')->pluck('angle')->all());
    }

    public function test_prompts_follow_the_scene_angle()
    {
        $scene = Scene::factory()->for($this->dressProject())->create(['angle' => 'fabric']);

        $image = GenerateSceneImageJob::scenePrompt($scene);
        $this->assertStringContainsString('Camera angle for this shot: Wide full-body shot; one hand smooths', $image);
        $this->assertStringContainsString('wide full-body shot taken from about 3 meters away', $image);
        $this->assertStringNotContainsString('close-up of', $image);

        // Khoe chất vải: không nhảy theo kiểu chuyển động, làm hành động của góc quay, vẫn toàn thân.
        $clip = MotionStyle::TiktokDance->prompt($scene);
        $this->assertStringContainsString('smooths and lightly pinches the fabric', $clip);
        $this->assertStringContainsString('no zoom-in, no push-in, no close-up', $clip);
        $this->assertStringNotContainsString(MotionStyle::TiktokDance->moves(), $clip);
        $this->assertStringContainsString(MotionStyle::NATURAL_CUT, $clip);

        $scene->update(['angle' => 'motion']);
        $this->assertStringContainsString(MotionStyle::TiktokDance->moves(), MotionStyle::TiktokDance->prompt($scene->fresh()));
    }

    public function test_renderer_skips_clip_lead_in()
    {
        $graph = (new FfmpegVideoRenderer('ffmpeg', resource_path('fonts')))->filterGraph(
            new RenderSpec([new RenderScene('/a.png', 2000, clipPath: '/c.mp4', clipOffsetMs: 400)], '', '/o.mp4'),
            '/s.ass',
        );

        $this->assertStringContainsString('trim=start=0.4:duration=2', $graph);
    }

    public function test_change_scene_angle()
    {
        $scene = Scene::factory()->for($this->dressProject())->create(['angle' => 'front_full']);
        $user = $scene->videoProject->user;

        $this->actingAs($user)->put(route('scenes.angle', $scene), ['angle' => 'nope'])->assertSessionHasErrors('angle');
        $this->actingAs($user)->put(route('scenes.angle', $scene), ['angle' => 'back'])->assertSessionHasNoErrors();
        $this->assertSame('back', $scene->fresh()->angle);
        $this->actingAs(User::factory()->create())->put(route('scenes.angle', $scene), ['angle' => null])->assertForbidden();
    }

    public function test_close_up_sentences_are_dropped_for_full_body_framing()
    {
        $prompt = Framing::strip('On the city street in daylight. She steps closer to the camera and tilts slightly. Close-up framed from the shoulders up, the neckline fills most of the frame. Vertical 9:16.');

        $this->assertSame('On the city street in daylight. Vertical 9:16.', $prompt);
        $this->assertSame('Người mẫu đứng cười.', Framing::strip('Người mẫu đứng cười. Cận cổ áo.'));
    }
}
