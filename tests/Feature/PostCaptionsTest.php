<?php

namespace Tests\Feature;

use App\Enums\VideoProjectStatus;
use App\Jobs\GeneratePostCaptionsJob;
use App\Models\VideoProject;
use App\Services\Script\PostCaptions;
use App\Services\Script\ScriptPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Đề xuất caption đăng video: 3 bản bán hàng, 3 bản câu view, viết sau khi có kịch bản và viết lại được. */
class PostCaptionsTest extends TestCase
{
    use RefreshDatabase;

    private function script(): array
    {
        return [
            'hooks' => ['Hook A', 'Hook B'], 'hook_themes' => [], 'recommended_hook' => 1, 'caption' => 'C', 'hashtags' => ['#a'],
            'scenes' => [['visual' => 'v', 'on_screen_text' => 'Nơ cổ xinh', 'voiceover' => 'Cái nơ nhỏ xinh xỉu', 'image_prompt' => 'p', 'duration_ms' => 5000]],
        ];
    }

    public function test_captions_are_written_after_script()
    {
        Queue::fake();
        $project = VideoProject::factory()->create(['status' => VideoProjectStatus::Scripting]);
        $project->applyScript($this->script());

        Queue::assertPushed(GeneratePostCaptionsJob::class);
        $this->assertSame('pending', $project->fresh()->post_captions_status);

        (new GeneratePostCaptionsJob($project))->handle();
        $project->refresh();
        $this->assertSame('ready', $project->post_captions_status);
        $this->assertCount(3, $project->post_captions['sale']);
        $this->assertCount(3, $project->post_captions['view']);

        // Claude nhận hook đang chọn và nội dung từng cảnh.
        $text = PostCaptions::userText($project);
        $this->assertStringContainsString('Hook mở video: Hook B', $text);
        $this->assertStringContainsString('- Nơ cổ xinh: Cái nơ nhỏ xinh xỉu', $text);
    }

    public function test_normalize_drops_promo_words_and_requires_both_groups()
    {
        $captions = PostCaptions::normalize(['sale' => ['Váy xinh. Xem màu, size, ưu đãi trong giỏ hàng!', 'b', 'c', 'd'], 'view' => ['Mấy điểm?']]);
        $this->assertSame('Váy xinh. Xem màu, size trong giỏ hàng!', $captions['sale'][0]);
        $this->assertCount(3, $captions['sale']);

        $this->expectException(\RuntimeException::class);
        PostCaptions::normalize(['sale' => ['a'], 'view' => []]);
    }

    public function test_user_can_rewrite_captions()
    {
        Queue::fake();
        $project = VideoProject::factory()->create(['status' => VideoProjectStatus::Reviewing, 'script' => $this->script()]);

        $this->actingAs($project->user)->post(route('projects.post-captions', $project))->assertRedirect();
        Queue::assertPushed(GeneratePostCaptionsJob::class);
        $this->assertSame('pending', $project->fresh()->post_captions_status);

        $this->actingAs(VideoProject::factory()->create()->user)->post(route('projects.post-captions', $project))->assertForbidden();
        $this->assertStringContainsString('KHÔNG nhắc giá', PostCaptions::SYSTEM.ScriptPrompt::SYSTEM);
    }
}
