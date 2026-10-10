<?php

namespace Tests\Feature;

use App\Enums\VideoProjectStatus;
use App\Models\Scene;
use App\Models\User;
use App\Models\VideoProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SceneOrderTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{VideoProject, list<int>} */
    private function projectWithScenes(VideoProjectStatus $status = VideoProjectStatus::Reviewing): array
    {
        $project = VideoProject::factory()->create(['status' => $status]);
        $ids = Scene::factory()->count(3)->for($project)->sequence(fn ($s) => ['position' => $s->index + 1, 'on_screen_text' => 'Cảnh '.($s->index + 1)])
            ->create()->pluck('id')->all();

        return [$project, $ids];
    }

    public function test_scenes_can_be_reordered()
    {
        [$project, [$a, $b, $c]] = $this->projectWithScenes();

        $this->actingAs($project->user)->put(route('projects.scenes.order', $project), ['scene_ids' => [$c, $a, $b]])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Đã đổi thứ tự'));

        // Quan hệ scenes() sắp theo position: video ghép theo thứ tự mới.
        $this->assertSame([$c, $a, $b], $project->scenes()->pluck('id')->all());
        $this->assertSame([1, 2, 3], $project->scenes()->pluck('position')->all());
    }

    public function test_order_must_contain_exactly_the_project_scenes()
    {
        [$project, [$a, $b, $c]] = $this->projectWithScenes();
        $foreign = Scene::factory()->create()->id;

        foreach ([[$a, $b], [$a, $b, $foreign], [$a, $a, $b], [$a, $b, $c, $foreign]] as $ids) {
            $this->actingAs($project->user)->put(route('projects.scenes.order', $project), ['scene_ids' => $ids])->assertSessionHasErrors();
        }

        $this->assertSame([$a, $b, $c], $project->scenes()->pluck('id')->all());
    }

    public function test_cannot_reorder_while_busy_or_others_project()
    {
        [$project, [$a, $b, $c]] = $this->projectWithScenes(VideoProjectStatus::Rendering);

        $this->actingAs($project->user)->put(route('projects.scenes.order', $project), ['scene_ids' => [$c, $b, $a]])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'đang xử lý'));
        $this->assertSame([$a, $b, $c], $project->scenes()->pluck('id')->all());

        $this->actingAs(User::factory()->create())->put(route('projects.scenes.order', $project), ['scene_ids' => [$c, $b, $a]])->assertForbidden();
    }
}
