<?php

namespace Tests\Feature;

use App\Jobs\GenerateShotImageJob;
use App\Jobs\WriteSequenceJob;
use App\Models\Episode;
use App\Models\Series;
use App\Models\Shot;
use App\Models\User;
use App\Services\Film\ShotPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Claude viết tập thành phân đoạn và shot; Muse vẽ khung hình từng shot theo ảnh chính nhân vật. */
class EpisodeWritingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Series $series;

    private Episode $episode;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('videoaff.disk'));
        $this->user = User::factory()->create();
        $this->actingAs($this->user)->post(route('series.store'), [
            'idea' => 'A curious boy and his best friend run a tiny detective club in their town.',
            'genre' => 'mystery', 'age_min' => 6, 'age_max' => 12, 'episode_count' => 2, 'episode_minutes' => 5,
        ]);
        $this->series = $this->user->series()->sole();
        $this->episode = $this->series->episodes()->first();
    }

    private function params(array $extra = []): array
    {
        return ['series' => $this->series->id, 'episode' => $this->episode->id, ...$extra];
    }

    private function write(): void
    {
        $this->actingAs($this->user)->post(route('series.episodes.write', $this->params()))->assertSessionHas('status');
    }

    public function test_claude_writes_outline_then_shots_per_sequence()
    {
        $this->write();

        $episode = $this->episode->fresh();
        $this->assertSame('storyboard', $episode->status);
        $this->assertCount(2, $episode->outline['sequences']);
        $this->assertSame(2, $episode->outline['written']);
        $shots = $episode->shots()->get();
        $this->assertCount(6, $shots);
        $this->assertSame([1, 1, 1, 2, 2, 2], $shots->pluck('sequence')->all());
        $this->assertSame('none', $shots[0]->image_status);

        [$milo, $lily] = $this->series->characters()->get()->all();
        // Người dẫn chuyện: character_id null; nhân vật lạ ("Nobody") bị bỏ; thời lượng đủ đọc lời thoại.
        $this->assertNull($shots[0]->dialogue[0]['character_id']);
        $this->assertSame([$milo->id], $shots[1]->character_ids);
        $this->assertSame([$milo->id, $lily->id], $shots[2]->character_ids);
        $this->assertGreaterThanOrEqual(5000, $shots[2]->duration_ms);
        $this->assertNotNull($shots[0]->series_location_id);
        $this->assertSame((int) $shots->sum('duration_ms'), $episode->duration_ms);

        $this->actingAs($this->user)->get(route('series.episodes.show', $this->params()))
            ->assertInertia(fn (Assert $page) => $page->component('series/episode')
                ->has('episode.shots', 6)
                ->has('episode.sequences', 2)
                ->where('episode.written', 2)
                ->has('cameras'));

        // Viết lại: thay toàn bộ shot cũ.
        $this->write();
        $this->assertSame(6, $this->episode->shots()->count());
        $this->assertNotContains($shots[0]->id, $this->episode->shots()->pluck('id')->all());
    }

    public function test_stale_sequence_job_from_old_run_does_nothing()
    {
        $this->write();
        $this->episode->update(['status' => 'writing', 'outline' => [...$this->episode->fresh()->outline, 'run' => 'new-run', 'written' => 0]]);

        (new WriteSequenceJob($this->episode, 'old-run', 0))->handle();

        $this->assertSame(0, $this->episode->fresh()->outline['written']);
    }

    public function test_resume_after_failed_sequence()
    {
        $this->write();
        $outline = $this->episode->fresh()->outline;
        $this->episode->shots()->where('sequence', 2)->delete();
        $this->episode->update(['status' => 'failed', 'error' => 'x', 'outline' => [...$outline, 'written' => 1]]);

        $this->actingAs($this->user)->post(route('series.episodes.write', $this->params()), ['resume' => true]);

        $this->assertSame('storyboard', $this->episode->fresh()->status);
        $this->assertSame(6, $this->episode->shots()->count());
        $this->assertSame($outline['run'], $this->episode->fresh()->outline['run']);
    }

    public function test_keyframes_need_character_images_then_muse_draws_each_shot()
    {
        $this->write();

        $this->actingAs($this->user)->post(route('series.episodes.keyframes', $this->params()))
            ->assertSessionHas('status', fn ($status) => str_contains($status, 'Chưa có ảnh chính cho: Milo'));
        $this->assertSame(0, $this->episode->shots()->where('image_status', 'pending')->count());

        foreach ($this->series->characters as $character) {
            Storage::disk(config('videoaff.disk'))->put("c/{$character->id}.png", 'x');
            $character->update(['main_image_path' => "c/{$character->id}.png", 'locked_at' => now()]);
        }

        Queue::fake();
        $this->actingAs($this->user)->post(route('series.episodes.keyframes', $this->params()));
        Queue::assertPushed(GenerateShotImageJob::class, 6);
        $this->assertSame('keyframing', $this->episode->fresh()->status);

        $shot = $this->episode->shots()->where('position', 3)->first();
        [$milo, $lily] = $this->series->characters()->get()->all();
        $this->assertSame(["c/{$milo->id}.png", "c/{$lily->id}.png"], ShotPrompt::references($shot));
        $prompt = ShotPrompt::build($shot, 'big smiles');
        $this->assertStringContainsString('Camera: two-shot', $prompt);
        $this->assertStringContainsString('- Milo (exactly as in attached image 1)', $prompt);
        $this->assertStringContainsString('- Lily (exactly as in attached image 2)', $prompt);
        $this->assertStringContainsString('Additional direction: big smiles.', $prompt);
        $this->assertStringContainsString(Series::DEFAULT_STYLE, $prompt);

        // Mỗi job: lần đầu gửi Muse, lần sau lấy ảnh (ảnh giả).
        foreach ($this->episode->shots()->get() as $each) {
            $job = new GenerateShotImageJob($each, ShotPrompt::build($each));
            $job->handle();
            $job->handle();
        }

        $this->assertSame(6, $this->episode->shots()->where('image_status', 'ready')->count());
        $this->assertSame('keyframed', $this->episode->fresh()->status);
        $this->assertSame(6, $this->user->creditTransactions()->where('reference', 'like', 'shot-image:%')->count());
        Storage::disk(config('videoaff.disk'))->assertExists($shot->fresh()->image_path);
    }

    public function test_edit_and_redraw_a_shot()
    {
        $this->write();
        $shot = $this->episode->shots()->first();
        $milo = $this->series->characters()->first();

        $this->actingAs($this->user)->put(route('series.episodes.shots.update', $this->params(['shot' => $shot->id])), [
            'camera' => 'close-up', 'action' => 'Milo winks at the camera.', 'character_ids' => [$milo->id],
            'dialogue' => [['character_id' => $milo->id, 'line' => 'Case closed!', 'emotion' => 'proud'], ['character_id' => null, 'line' => 'And so it ended.']],
            'seconds' => 3.5,
        ])->assertSessionHasNoErrors();

        $shot->refresh();
        $this->assertSame('close-up', $shot->camera);
        $this->assertSame(3500, $shot->duration_ms);
        $this->assertSame([$milo->id], $shot->character_ids);
        $this->assertNull($shot->dialogue[1]['character_id']);

        $this->actingAs($this->user)->put(route('series.episodes.shots.update', $this->params(['shot' => $shot->id])), [
            'camera' => 'drone shot', 'action' => 'x', 'seconds' => 3,
        ])->assertSessionHasErrors('camera');

        // Chưa có ảnh nhân vật: chưa vẽ được; có rồi thì vẽ lại.
        $this->actingAs($this->user)->post(route('series.episodes.shots.image', $this->params(['shot' => $shot->id])))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Chưa có ảnh chính'));
        Storage::disk(config('videoaff.disk'))->put('c/milo.png', 'x');
        $milo->update(['main_image_path' => 'c/milo.png']);
        Queue::fake();
        $this->actingAs($this->user)->post(route('series.episodes.shots.image', $this->params(['shot' => $shot->id])), ['notes' => 'at night']);
        Queue::assertPushed(GenerateShotImageJob::class, fn ($job) => str_contains($job->prompt, 'at night') && $job->shot->is($shot));
        $this->assertSame('pending', $shot->fresh()->image_status);
    }

    public function test_other_users_cannot_write_or_draw()
    {
        $other = User::factory()->create();

        $this->actingAs($other)->post(route('series.episodes.write', $this->params()))->assertForbidden();
        $this->actingAs($other)->post(route('series.episodes.keyframes', $this->params()))->assertForbidden();
        $this->assertSame('draft', $this->episode->fresh()->status);
        $this->assertSame(0, Shot::count());
    }
}
