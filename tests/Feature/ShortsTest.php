<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeVocalsJob;
use App\Jobs\GenerateShotImageJob;
use App\Jobs\MusicVideoAutopilotJob;
use App\Jobs\RenderEpisodeJob;
use App\Jobs\WriteEpisodeJob;
use App\Models\Series;
use App\Models\User;
use App\Models\VirtualBand;
use App\Services\Film\EpisodeRenderer;
use App\Services\Film\ShortTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Series Shorts nhạc: ban nhạc ảo, mẫu Shorts, tạo hàng loạt MV ngắn, chữ trên hình, chạy tự động. */
class ShortsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('videoaff.disk'));
        $this->user = User::factory()->create();
        Queue::fake([AnalyzeVocalsJob::class]);
    }

    private function band(): VirtualBand
    {
        return $this->user->virtualBands()->create([
            'name' => 'Velvet Thunder', 'genre' => 'hard rock', 'era' => '1970s', 'lore' => 'Harbor town rockers.', 'style_preset' => 'cinematic', 'status' => 'ready',
            'members' => [
                ['name' => 'Jax Rivera', 'role' => 'lead vocals', 'look' => 'Long curly black hair, red satin shirt.', 'look_old' => 'White curly hair, leather jacket.', 'image_path' => null],
                ['name' => 'Mona Kade', 'role' => 'lead guitar', 'look' => 'Platinum shag hair, white fringe jacket.', 'look_old' => 'Short silver hair.', 'image_path' => null],
            ],
        ]);
    }

    private function song(): int
    {
        return $this->user->audioTracks()->create(['name' => 'Thunder Road Nights', 'path' => 'audio/song.mp3', 'duration_ms' => 180000, 'lyrics' => "[Chorus]\nThunder tonight"])->id;
    }

    public function test_claude_creates_a_virtual_band_and_real_names_are_blocked()
    {
        $this->actingAs($this->user)->post(route('shorts.bands.store'), ['mode' => 'ai', 'genre' => 'hard rock', 'era' => '1970s', 'fictional' => 1])->assertSessionHasNoErrors();
        $band = $this->user->virtualBands()->first();
        $this->assertSame('ready', $band->status);
        $this->assertSame('Velvet Thunder', $band->name);
        $this->assertSame(['Jax Rivera', 'Mona Kade'], array_column($band->members, 'name'));
        $this->assertNotEmpty($band->members[0]['look_old']);

        $this->actingAs($this->user)->post(route('shorts.bands.store'), [
            'mode' => 'manual', 'genre' => 'rock', 'era' => '1980s', 'name' => 'Pikachu Band', 'members' => [['name' => 'A', 'look' => 'x']], 'fictional' => 1,
        ])->assertSessionHasErrors('name');
        // Nghệ sĩ thật trong tên / ngoại hình bị chặn; phải xác nhận nhân vật hư cấu.
        $this->actingAs($this->user)->post(route('shorts.bands.store'), [
            'mode' => 'manual', 'genre' => 'metal', 'era' => '1980s', 'name' => 'Night Owls', 'members' => [['name' => 'Rex', 'look' => 'looks exactly like James Hetfield']], 'fictional' => 1,
        ])->assertSessionHasErrors('name');
        $this->actingAs($this->user)->post(route('shorts.bands.store'), ['mode' => 'ai', 'genre' => 'metal', 'era' => '1980s'])->assertSessionHasErrors('fictional');
        $this->actingAs($this->user)->post(route('shorts.bands.store'), ['mode' => 'ai', 'genre' => 'metal', 'era' => '1980s', 'notes' => 'like Metallica', 'fictional' => 1])
            ->assertSessionHasErrors('name');
        $this->assertSame(1, $this->user->virtualBands()->count());

        $this->actingAs($this->user)->get(route('shorts.index'))->assertInertia(fn (Assert $page) => $page
            ->component('music-videos/shorts')
            ->where('bands.0.name', 'Velvet Thunder')
            ->where('bands.0.variations.lineup', ['1970', '1974', '1979'])
            ->where('bands.0.variations.versus.0', 'Jax Rivera | Mona Kade')
            ->has('templates.fanwalk'));
    }

    public function test_templates_build_titles_ideas_shot_plans_and_post_text()
    {
        $band = $this->band();
        $lineup = ShortTemplates::build('lineup', $band, '1977', 'Thunder Road Nights');
        $this->assertSame('Velvet Thunder — Classic Lineup (1977)', $lineup['title']);
        $this->assertStringContainsString('FICTIONAL hard rock band', $lineup['idea']);
        $this->assertStringContainsString('never depict or name real artists', $lineup['idea']);
        $this->assertStringContainsString('- Jax Rivera (lead vocals)', $lineup['idea']);
        $this->assertStringContainsString('0–2s HOOK', $lineup['brief']);
        $this->assertContains('#VelvetThunder', $lineup['hashtags']);
        $this->assertContains('#70shard', $lineup['hashtags']);
        $this->assertStringStartsWith('⚠️ This is an AI-generated video', $lineup['description']);
        $this->assertSame('chorus', $lineup['lip_sync']);

        $versus = ShortTemplates::build('versus', $band, 'Jax Rivera | Mona Kade', 'Thunder Road Nights');
        $this->assertSame('Jax Rivera VS Mona Kade — Thunder Road Nights', $versus['title']);
        $this->assertCount(2, $versus['members']);
        $this->assertSame('full', $versus['lip_sync']);

        $ages = ShortTemplates::build('ages', $band, 'Jax Rivera', 'Thunder Road Nights');
        $this->assertSame(['Jax Rivera (1970)', 'Jax Rivera ('.now()->year.')'], array_column($ages['members'], 'name'));
        $this->assertSame('White curly hair, leather jacket.', $ages['members'][1]['look']);
    }

    public function test_batch_creates_planned_short_music_videos_with_overlay_and_post()
    {
        $band = $this->band();
        $song = $this->song();
        $payload = ['band_id' => $band->id, 'template' => 'lineup', 'variations' => "1971\n1977\n", 'audio_track_id' => $song, 'song_start' => 60, 'seconds' => 25, 'driver' => 'fake', 'fictional' => 1];

        $this->actingAs($this->user)->post(route('shorts.batch'), $payload)->assertSessionHasErrors('confirm');
        $this->actingAs($this->user)->postJson(route('shorts.preview'), $payload)->assertOk()
            ->assertJsonPath('items.1.title', 'Velvet Thunder — Classic Lineup (1977)');

        $this->actingAs($this->user)->post(route('shorts.batch'), [...$payload, 'confirm' => 1, 'autopilot' => 0])
            ->assertRedirect(route('music-videos.index', ['band' => $band->id]));

        $shorts = $band->musicVideos()->orderBy('id')->get();
        $this->assertCount(2, $shorts);
        $mv = $shorts[1];
        $this->assertTrue($mv->musicVideo());
        $this->assertSame('Velvet Thunder — Classic Lineup (1977)', $mv->title);
        $this->assertSame('9:16', $mv->aspect_ratio);
        $this->assertSame([60000, 85000], $mv->songRange());
        $this->assertSame(['title' => 'Velvet Thunder — Classic Lineup (1977)', 'labels' => true], $mv->mv_options['overlay']);
        $this->assertSame('lineup', $mv->mv_options['template']);
        // Kế hoạch chạy (hàng đợi sync): tập MV có sẵn kịch bản shot theo mẫu.
        $this->assertSame('bible_ready', $mv->status->value);
        $this->assertStringContainsString('0–2s HOOK', $mv->episodes()->first()->brief);

        $this->actingAs($this->user)->get(route('music-videos.index', ['band' => $band->id]))->assertInertia(fn (Assert $page) => $page
            ->where('bandFilter', $band->id)
            ->where('bands.0.name', 'Velvet Thunder')
            ->where('musicVideos.0.band_id', $band->id)
            ->has('musicVideos.0.post.hashtags'));
    }

    public function test_member_images_from_a_finished_short_are_reused_by_next_shorts()
    {
        $band = $this->band();
        $song = $this->song();
        $payload = ['band_id' => $band->id, 'template' => 'lineup', 'variations' => '1971', 'audio_track_id' => $song, 'seconds' => 25, 'driver' => 'fake', 'confirm' => 1, 'fictional' => 1];
        $this->actingAs($this->user)->post(route('shorts.batch'), $payload);
        $first = $band->musicVideos()->first();
        $first->characters()->where('name', 'Jax Rivera')->first()?->update(['main_image_path' => 'series/jax.png', 'locked_at' => now()])
            ?? $first->characters()->create(['name' => 'Jax Rivera', 'role' => 'main', 'appearance' => 'x', 'main_image_path' => 'series/jax.png', 'locked_at' => now()]);

        $this->actingAs($this->user)->post(route('shorts.batch'), [...$payload, 'variations' => '1977']);
        $this->assertSame('series/jax.png', $band->fresh()->members[0]['image_path']);
        $second = $band->musicVideos()->latest('id')->first();
        $this->assertSame([['name' => 'Jax Rivera', 'note' => 'lead vocals. Long curly black hair, red satin shirt.', 'path' => 'series/jax.png']], $second->seed_characters);
        $this->assertSame('series/jax.png', $second->characters()->where('name', 'Jax Rivera')->value('main_image_path'));
    }

    public function test_autopilot_moves_a_short_through_the_pipeline()
    {
        Queue::fake([AnalyzeVocalsJob::class, MusicVideoAutopilotJob::class, WriteEpisodeJob::class, GenerateShotImageJob::class, RenderEpisodeJob::class]);
        $band = $this->band();
        $this->actingAs($this->user)->post(route('shorts.batch'), [
            'band_id' => $band->id, 'template' => 'versus', 'variations' => 'Jax Rivera | Mona Kade', 'audio_track_id' => $this->song(),
            'seconds' => 25, 'driver' => 'fake', 'confirm' => 1, 'autopilot' => 1, 'fictional' => 1,
        ]);
        Queue::assertPushed(MusicVideoAutopilotJob::class);
        $mv = $band->musicVideos()->first();
        $this->assertSame('bible_ready', $mv->status->value);
        $tick = fn () => (new MusicVideoAutopilotJob($mv))->handle();

        // Thành viên chưa có ảnh: dừng, ghi lý do.
        $tick();
        $this->assertFalse($mv->fresh()->mv_options['autopilot']);
        $this->assertStringContainsString('cần ảnh chính', $mv->fresh()->mv_options['autopilot_note']);

        $mv->characters()->update(['main_image_path' => 'series/x.png']);
        $this->actingAs($this->user)->post(route('music-videos.autopilot', $mv), ['on' => 1])->assertSessionHasNoErrors();
        $tick();
        Queue::assertPushed(WriteEpisodeJob::class);
        $episode = $mv->episodes()->first();
        $this->assertSame('writing', $episode->fresh()->status);

        // Viết xong (chạy tay job viết): vẽ khung hình mọi shot.
        (new WriteEpisodeJob($episode, $episode->fresh()->outline['run']))->handle();
        $tick();
        Queue::assertPushed(GenerateShotImageJob::class, $episode->shots()->count());
        $this->assertSame('keyframing', $episode->fresh()->status);

        // Có clip cho mọi shot: ghép MV với bài hát và chữ trên hình.
        $episode->shots()->update(['image_status' => 'ready', 'image_path' => 'f.png', 'clip_status' => 'ready', 'video_path' => 'v.mp4']);
        $episode->update(['status' => 'animated']);
        $tick();
        Queue::assertPushed(RenderEpisodeJob::class);
        $this->assertTrue($episode->fresh()->render_options['song']);

        $episode->update(['render_status' => 'done', 'render_path' => 'mv.mp4']);
        $tick();
        $this->assertSame('Xong.', $mv->fresh()->mv_options['autopilot_note']);
    }

    public function test_overlay_draws_title_and_per_shot_labels()
    {
        $series = $this->user->series()->create(['title' => 'X', 'idea' => 'x', 'format' => 'music_video', 'genre' => 'music', 'age_min' => 13, 'age_max' => 17,
            'made_for_kids' => false, 'style' => 'x', 'episode_count' => 1, 'aspect_ratio' => '9:16', 'status' => 'bible_ready',
            'mv_options' => ['overlay' => ['title' => 'Velvet Thunder — Classic Lineup (1977)', 'labels' => true]]]);
        $episode = $series->episodes()->create(['number' => 1, 'title' => 'X', 'synopsis' => 'x']);
        $a = $episode->shots()->create(['action' => 'a', 'label' => 'Jax Rivera — vocals', 'duration_ms' => 3000, 'position' => 1]);
        $b = $episode->shots()->create(['action' => 'b', 'label' => 'Jax Rivera — vocals', 'duration_ms' => 2000, 'position' => 2]);
        $c = $episode->shots()->create(['action' => 'c', 'label' => null, 'duration_ms' => 2000, 'position' => 3]);
        $plan = [['shot_id' => $a->id, 'offset_ms' => 0, 'duration_ms' => 3000], ['shot_id' => $b->id, 'offset_ms' => 3000, 'duration_ms' => 2000], ['shot_id' => $c->id, 'offset_ms' => 5000, 'duration_ms' => 2000]];
        $dir = sys_get_temp_dir().'/overlay-test-'.uniqid();
        File::ensureDirectoryExists($dir);

        $filter = EpisodeRenderer::overlay($episode, $plan, $dir, '9:16');

        $this->assertSame(2, substr_count($filter, 'drawtext='));
        // Hai shot liền nhau cùng chữ: một lần hiện từ 0 tới 5 giây.
        $this->assertStringContainsString("enable='between(t,0,5)'", $filter);
        $this->assertSame('Jax Rivera — vocals', File::get("{$dir}/overlay-label-0.txt"));
        $this->assertSame('Velvet Thunder — Classic Lineup (1977)', File::get("{$dir}/overlay-title.txt"));
        File::deleteDirectory($dir);
        $series->update(['mv_options' => []]);
        $this->assertSame('', EpisodeRenderer::overlay($episode->fresh(), $plan, $dir, '9:16'));
    }
}
