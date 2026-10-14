<?php

namespace Tests\Feature;

use App\Jobs\AnimateShotJob;
use App\Jobs\GenerateShotImageJob;
use App\Jobs\RenderEpisodeJob;
use App\Jobs\WriteEpisodeJob;
use App\Models\Series;
use App\Models\User;
use App\Services\Film\CharacterSounds;
use App\Services\Film\EpisodeList;
use App\Services\Film\EpisodeRenderer;
use App\Services\Film\SeriesPlanner;
use App\Services\Film\ShotClipPrompt;
use App\Services\Film\ShotPrompt;
use App\Services\Film\SoundLibrary;
use App\Services\Film\SoundPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Truyện ngắn không thoại 9:16 (kế hoạch kênh kiểu "Milo's Little World"): danh sách tập có sẵn, gói sản xuất, clip AI có chọn lọc, ghép dọc. */
class SilentShortTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = <<<'MD'
    | # | Tựa đề tiếng Anh | Ý tưởng ngắn | Thể loại |
    |---:|---|---|---|
    | 01 | The Last Cookie | Milo chia chiếc bánh cuối cùng cho Bobo; Bobo có một bất ngờ. | Cảm động |
    | 02 | The Stolen Fish | Milo tưởng Bobo lấy trộm cá; thủ phạm thực ra là chim. | Hài hước |
    | 03 | The Lonely Star | Milo tưởng đom đóm là ngôi sao đi lạc. | Kỳ diệu |
    MD;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('videoaff.disk'));
        $this->user = User::factory()->create();
    }

    private function createSeries(array $override = []): Series
    {
        $this->actingAs($this->user)->post(route('series.store'), [
            'format' => 'silent_short',
            'idea' => "Milo's Little World: original 3D series about a tiny orange tabby kitten Milo (teal scarf) and Bobo, a cream corgi with a blue collar.",
            'genre' => 'comedy', 'age_min' => 6, 'age_max' => 12, 'episode_count' => 30, 'episode_seconds' => 30,
            'episode_list' => self::TABLE,
            'briefs' => [['number' => 1, 'text' => "Cảnh 1 (0–5s): Milo ngồi ghế gỗ nhìn chiếc bánh quy cuối cùng.\nCảnh 2: Bobo đến gần, bụng réo."]],
            'clip_budget' => 2,
            ...$override,
        ])->assertRedirect()->assertSessionHasNoErrors();

        return $this->user->series()->latest('id')->first();
    }

    public function test_episode_list_reads_markdown_table_and_plain_lines()
    {
        $list = EpisodeList::parse(self::TABLE);
        $this->assertCount(3, $list);
        $this->assertSame(['title' => 'The Last Cookie', 'idea' => 'Milo chia chiếc bánh cuối cùng cho Bobo; Bobo có một bất ngờ.', 'kind' => 'heartwarming'], $list[0]);
        $this->assertSame(['funny', 'magical'], [$list[1]['kind'], $list[2]['kind']]);

        $plain = EpisodeList::parse("The Red Balloon | Milo lấy lại bóng bay | Phiêu lưu\n\nThe Paper Boat — Milo gửi lời chúc\n3. The Wrong Hat");
        $this->assertSame(['The Red Balloon', 'The Paper Boat', 'The Wrong Hat'], array_column($plain, 'title'));
        $this->assertSame(['adventure', null, null], array_column($plain, 'kind'));
        $this->assertSame('Milo gửi lời chúc', $plain[1]['idea']);
    }

    public function test_creating_short_series_keeps_episode_list_and_scripts()
    {
        $series = $this->createSeries();

        $this->assertTrue($series->silent());
        $this->assertSame('9:16', $series->aspect_ratio);
        $this->assertSame(30, $series->episode_seconds);
        $this->assertSame(2, $series->clip_budget);
        $this->assertFalse($series->made_for_kids);
        $this->assertSame(Series::STYLE_3D, $series->style);
        $this->assertSame(Series::SHORT_AUDIENCE, $series->audience);
        // Số tập theo danh sách (không theo ô số tập), giữ đúng tên, thể loại, kịch bản có sẵn.
        $this->assertSame(3, $series->episode_count);
        $episodes = $series->episodes()->get();
        $this->assertSame(['The Last Cookie', 'The Stolen Fish', 'The Lonely Star'], $episodes->pluck('title')->all());
        $this->assertSame(['heartwarming', 'funny', 'magical'], $episodes->pluck('kind')->all());
        $this->assertStringContainsString('bánh quy cuối cùng', $episodes[0]->brief);
        $this->assertNull($episodes[1]->brief);

        $text = SeriesPlanner::userText($series);
        $this->assertStringContainsString('Keep exactly these titles', $text);
        $this->assertStringContainsString('1. The Last Cookie: Milo chia', $text);
        $this->assertStringContainsString('no dialogue', $text);
        $this->assertArrayHasKey('kind', SeriesPlanner::schema(true)['properties']['episodes']['items']['properties']);

        $this->actingAs($this->user)->get(route('series.show', $series))
            ->assertInertia(fn (Assert $page) => $page->where('series.format', 'silent_short')->where('series.episodes.0.kind_label', 'Cảm động')->where('series.episodes.0.has_brief', true));
    }

    public function test_writing_short_makes_package_and_animates_only_chosen_shots()
    {
        $series = $this->createSeries();
        $episode = $series->episodes()->first();
        $this->actingAs($this->user)->post(route('series.episodes.write', ['series' => $series->id, 'episode' => $episode->id]))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'trọn gói'));

        $episode->refresh();
        $this->assertSame('storyboard', $episode->status, (string) $episode->error);
        $shots = $episode->shots()->get();
        $this->assertCount(5, $shots);
        $this->assertSame([1], $shots->pluck('sequence')->unique()->values()->all());
        $this->assertSame([], $shots->pluck('dialogue')->flatten()->all());
        // Claude đánh dấu 3 shot nhưng chỉ chọn 2 clip AI / tập.
        $this->assertSame([true, true, false, false, false], $shots->pluck('animate')->all());
        $this->assertSame('push_in', $shots[0]->camera_move);
        $this->assertNotNull($shots[0]->motion);
        $this->assertSame('birds chirping; soft playful music', $shots[0]->sound_cue);
        $this->assertSame(30000, $episode->duration_ms);
        $this->assertCount(3, $episode->package['hooks']);
        $this->assertSame(['#CatStories', '#AnimatedShorts', '#Wholesome'], $episode->package['hashtags']);
        $this->assertSame('hook', $episode->package['beats'][0]);

        $shot = $shots[0]->setRelation('episode', $episode);
        $prompt = ShotPrompt::build($shot);
        $this->assertStringContainsString('TALL VERTICAL 9:16', $prompt);
        $this->assertStringContainsString('preserve exact reference identity', $prompt);
        $this->assertStringContainsString('exactly four legs', $prompt);
        $this->assertStringContainsString(Series::STYLE_3D, $prompt);
        $clip = ShotClipPrompt::build($shot);
        $this->assertStringContainsString('Animate the supplied reference frame for 5 seconds', $clip);
        $this->assertStringContainsString($shots[0]->motion, $clip);
        $this->assertStringContainsString('camera slowly pushes in', $clip);
        $this->assertStringContainsString('No dialogue', $clip);

        // "Tạo video" chỉ làm clip cho shot chọn Clip AI; bật thêm một shot thì làm thêm shot đó.
        $episode->shots()->update(['image_status' => 'ready', 'image_path' => 'k.png']);
        Queue::fake();
        $params = ['series' => $series->id, 'episode' => $episode->id];
        $this->actingAs($this->user)->post(route('series.episodes.clips', $params))->assertSessionHas('status', fn ($s) => str_contains($s, 'clip 2 shot'));
        Queue::assertPushed(AnimateShotJob::class, 2);
        $this->actingAs($this->user)->put(route('series.episodes.shots.animate', [...$params, 'shot' => $shots[4]->id]), ['animate' => true]);
        $this->assertTrue($shots[4]->fresh()->animate);

        $this->actingAs($this->user)->get(route('series.episodes.show', $params))->assertInertia(fn (Assert $page) => $page
            ->where('series.silent', true)->has('aspects', 2)->where('episode.render.options.aspect', '9:16')
            ->where('episode.render.options.title_card', false)->where('episode.render.options.dialogue', false)
            ->where('episode.package.title', 'He Gave Away His Last Cookie 🥹🍪')->where('episode.shots.0.camera_move', 'push_in'));
    }

    public function test_keyframes_follow_previous_shot_and_continuity_list()
    {
        $series = $this->createSeries();
        $episode = $series->episodes()->first();
        $this->actingAs($this->user)->post(route('series.episodes.write', ['series' => $series->id, 'episode' => $episode->id]));
        $episode->refresh()->update(['package' => [...$episode->package, 'continuity' => ['Jam jar: small round glass jar, deep red jam, brass lid.']]]);
        $series->characters()->update(['main_image_path' => 'c.png']);
        [$first, $second] = $episode->shots()->get()->all();

        // Danh sách giữ liên tục có trong prompt khung hình và clip.
        $this->assertStringContainsString('brass lid', ShotPrompt::build($second->fresh()));
        $this->assertStringContainsString('brass lid', ShotClipPrompt::build($second->fresh()));
        $this->assertTrue(ShotPrompt::anchor($second)->is($first));
        $this->assertNull(ShotPrompt::anchor($first));

        // Shot trước đang vẽ: shot sau chờ; vẽ xong thì shot sau gửi kèm khung hình shot trước + lời dặn giữ bối cảnh.
        $first->update(['image_status' => 'pending']);
        $second->update(['image_status' => 'pending', 'request_id' => null]);
        (new GenerateShotImageJob($second, 'prompt'))->handle();
        $this->assertNull($second->fresh()->request_id);
        $first->update(['image_status' => 'ready', 'image_path' => 'first.png']);
        $this->assertContains('first.png', ShotPrompt::references($second->fresh(), true));
        $job = new GenerateShotImageJob($second, 'prompt');
        $job->handle();
        $this->assertNotNull($second->fresh()->request_id);
        $this->assertStringContainsString('SET CONTINUITY', $job->prompt);
    }

    public function test_batch_writes_next_episodes()
    {
        $series = $this->createSeries();
        Queue::fake();
        $this->actingAs($this->user)->post(route('series.episodes.batch', $series), ['count' => 2])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'tập 1, tập 2'));
        Queue::assertPushed(WriteEpisodeJob::class, 2);
        $this->assertSame(['writing', 'writing', 'draft'], $series->episodes()->pluck('status')->all());
    }

    public function test_vertical_short_renders_native_with_camera_moves_on_stills()
    {
        $series = $this->createSeries();
        $episode = $series->episodes()->first();
        $params = ['series' => $series->id, 'episode' => $episode->id];
        $this->actingAs($this->user)->post(route('series.episodes.write', $params));
        $disk = Storage::disk(config('videoaff.disk'));
        $make = function (string $ext, array $args) {
            $file = tempnam(sys_get_temp_dir(), 'media').'.'.$ext;
            Process::run(['ffmpeg', '-v', 'error', '-y', ...$args, $file])->throw();

            return (string) file_get_contents($file);
        };
        $disk->put('k.png', $make('png', ['-f', 'lavfi', '-i', 'color=c=orange:s=360x640', '-frames:v', '1']));
        $disk->put('clip.mp4', $make('mp4', ['-f', 'lavfi', '-i', 'color=c=blue:s=360x640:d=2:r=24', '-c:v', 'libx264', '-pix_fmt', 'yuv420p']));
        foreach ($episode->shots()->get() as $i => $shot) {
            $shot->update(['image_status' => 'ready', 'image_path' => 'k.png', 'duration_ms' => 1000, 'video_path' => $i === 0 ? 'clip.mp4' : null,
                'camera_move' => ['push_in', 'pan_left', 'tilt_up', 'pull_out', 'static'][$i]]);
        }
        $this->assertStringContainsString("x='(iw-iw/zoom)*(1-on/24)'", EpisodeRenderer::move('pan_left', 24));

        foreach (['9:16' => [1080, 1920], '16:9_blur' => [1920, 1080]] as $aspect => $size) {
            Queue::fake();
            $this->actingAs($this->user)->post(route('series.episodes.render', $params), ['aspect' => $aspect, 'sound_effects' => false])->assertSessionHasNoErrors();
            $episode->refresh();
            $this->assertFalse($episode->render_options['title_card']);
            (new RenderEpisodeJob($episode, $episode->render_options['run']))->handle();
            $episode->refresh();
            $this->assertSame('done', $episode->render_status, (string) $episode->render_error);

            $file = tempnam(sys_get_temp_dir(), 'ep').'.mp4';
            file_put_contents($file, $disk->get($episode->render_path));
            $probe = json_decode(Process::run(['ffprobe', '-v', 'error', '-show_entries', 'stream=width,height:format=duration', '-of', 'json', $file])->output(), true);
            @unlink($file);
            $this->assertSame($size, [$probe['streams'][0]['width'], $probe['streams'][0]['height']]);
            // Không thẻ tiêu đề / thẻ kết: vào thẳng cảnh đầu, 5 shot × 1 giây.
            $this->assertEqualsWithDelta(5.0, (float) $probe['format']['duration'], 0.3);
        }
        $this->assertSame([false, true], $episode->renders()->get()->map->vertical()->reverse()->values()->map(fn ($v) => ! $v)->all());
        // Khung không hợp với phim dọc bị từ chối.
        $this->actingAs($this->user)->post(route('series.episodes.render', $params), ['aspect' => '9:16_crop'])->assertSessionHasErrors('aspect');
    }

    public function test_characters_laugh_cry_and_make_their_species_sound_with_own_pitch()
    {
        $series = $this->createSeries();
        $episode = $series->episodes()->first();
        $params = ['series' => $series->id, 'episode' => $episode->id];
        $this->actingAs($this->user)->post(route('series.episodes.write', $params));
        $milo = $series->characters()->where('name', 'Milo')->sole();
        $bobo = $series->characters()->where('name', 'Bobo')->sole();
        $this->assertSame(['cat', 'dog'], [CharacterSounds::species($milo), CharacterSounds::species($bobo)]);
        $this->assertNotEquals(CharacterSounds::pitch($milo, 'voice'), CharacterSounds::pitch($bobo, 'voice'));
        $this->assertStringStartsWith('asetrate=', CharacterSounds::filter(1.2));

        // Kho giả: tiếng người, tiếng mèo, tiếng chó, một hiệu ứng thường.
        $disk = Storage::disk(config('videoaff.disk'));
        $make = function () {
            $file = tempnam(sys_get_temp_dir(), 'snd').'.wav';
            Process::run(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=f=600:d=0.4', $file])->throw();

            return (string) file_get_contents($file);
        };
        $entries = [];
        foreach (['voice_laugh' => ['voice', null], 'cat_meow' => ['animal', 'cat'], 'dog_bark' => ['animal', 'dog'], 'cartoon_pop' => ['cartoon', null]] as $id => [$group, $species]) {
            $disk->put("sounds/library/{$id}.wav", $make());
            $entries[] = ['id' => $id, 'group' => $group, 'description' => $id, 'files' => ["sounds/library/{$id}.wav"], 'duration_ms' => 400, 'source' => 'test', 'species' => $species];
        }
        $disk->put(SoundLibrary::CATALOG, json_encode($entries));

        $shots = $episode->shots()->get()->all();
        $clean = SoundPlanner::normalize([
            ['place' => 'shot', 'shot' => 1, 'sound' => 'cat_meow', 'character' => 'Milo', 'at' => 0.5, 'volume' => 0.6],
            ['place' => 'shot', 'shot' => 1, 'sound' => 'cat_meow', 'character' => 'Bobo', 'at' => 0.5, 'volume' => 0.6],
            ['place' => 'shot', 'shot' => 2, 'sound' => 'voice_laugh', 'character' => 'bobo', 'at' => 0.2, 'volume' => 0.6],
            ['place' => 'shot', 'shot' => 2, 'sound' => 'cartoon_pop', 'character' => 'Milo', 'at' => 0.1, 'volume' => 0.5],
            ['place' => 'shot', 'shot' => 3, 'sound' => 'dog_bark', 'character' => null, 'at' => 0.1, 'volume' => 0.5],
        ], $shots, SoundLibrary::byId(), $series->characters()->get()->all());
        // Mèo kêu gắn với chó thì bỏ; hiệu ứng thường không gắn nhân vật; tiếng chó không nhân vật là tiếng nền.
        $this->assertSame(['cat_meow', 'voice_laugh', 'cartoon_pop', 'dog_bark'], array_column($clean, 'sound'));
        $this->assertSame([$milo->id, $bobo->id, null, null], array_column($clean, 'character_id'));
        $this->assertNotEmpty(SoundPlanner::plan($episode->fresh()));

        // Ghép tập: tiếng nhân vật đổi cao độ theo nhân vật mà vẫn ghép được.
        $episode->update(['sound_plan' => $clean]);
        $disk->put('k.png', (function () {
            $file = tempnam(sys_get_temp_dir(), 'img').'.png';
            Process::run(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=orange:s=360x640', '-frames:v', '1', $file])->throw();

            return (string) file_get_contents($file);
        })());
        $episode->shots()->update(['image_status' => 'ready', 'image_path' => 'k.png', 'duration_ms' => 1000]);
        Queue::fake();
        $this->actingAs($this->user)->post(route('series.episodes.render', $params), ['sound_effects' => true]);
        $episode->refresh();
        (new RenderEpisodeJob($episode, $episode->render_options['run']))->handle();
        $this->assertSame('done', $episode->fresh()->render_status, (string) $episode->fresh()->render_error);
        $this->actingAs($this->user)->get(route('series.episodes.show', $params))
            ->assertInertia(fn (Assert $page) => $page->where('episode.sounds.items.0.character', 'Milo'));

        // Trang nhân vật: nghe thử tiếng của Milo (tiếng người + tiếng mèo, không có tiếng chó).
        $base = ['series' => $series->id, 'character' => $milo->id];
        $this->actingAs($this->user)->get(route('series.characters.show', $base))
            ->assertInertia(fn (Assert $page) => $page->where('species', 'cat')->where('sounds', fn ($sounds) => collect($sounds)->pluck('id')->sort()->values()->all() === ['cat_meow', 'voice_laugh']));
        $this->actingAs($this->user)->get(route('series.characters.sound', [...$base, 'sound' => 'cat_meow']))->assertOk();
        $this->actingAs($this->user)->get(route('series.characters.sound', [...$base, 'sound' => 'dog_bark']))->assertNotFound();
    }
}
