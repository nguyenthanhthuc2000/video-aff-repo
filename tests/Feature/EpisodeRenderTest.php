<?php

namespace Tests\Feature;

use App\Jobs\GenerateThumbnailArtJob;
use App\Jobs\RenderEpisodeJob;
use App\Models\AudioTrack;
use App\Models\Episode;
use App\Models\Series;
use App\Models\User;
use App\Services\Film\EpisodeRenderer;
use App\Services\Film\EpisodeTitler;
use App\Services\Film\SoundLibrary;
use App\Services\Film\SoundPlanner;
use App\Services\Film\Thumbnail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Ghép cả tập: thẻ tiêu đề, các shot (video hoặc khung hình + lời), thẻ kết, nhạc nền, phụ đề .srt. */
class EpisodeRenderTest extends TestCase
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
        $this->actingAs($this->user)->post(route('series.episodes.write', $this->params()));
    }

    private function params(): array
    {
        return ['series' => $this->series->id, 'episode' => $this->episode->id];
    }

    private static function ffmpeg(array $args): string
    {
        $file = tempnam(sys_get_temp_dir(), 'media').'.'.$args[0];
        Process::run(['ffmpeg', '-v', 'error', '-y', ...array_slice($args, 1), $file])->throw();

        return (string) file_get_contents($file);
    }

    public function test_plan_fades_between_sequences_and_cuts_inside()
    {
        $plan = EpisodeRenderer::plan($this->episode, EpisodeRenderer::DEFAULT_OPTIONS);
        $this->assertSame(['title', 'still', 'still', 'still', 'still', 'still', 'still', 'end'], array_column($plan, 'type'));
        // Shot 3 hết phân đoạn 1 thì mờ ra, shot 4 mở phân đoạn 2 thì mờ vào; giữa phân đoạn cắt thẳng.
        $this->assertSame([true, false, false, true, false, false, true, true], array_column($plan, 'fade_out'));
        $this->assertSame([true, true, false, false, true, false, false, true], array_column($plan, 'fade_in'));
        $this->assertSame(EpisodeRenderer::TITLE_MS, $plan[1]['offset_ms']);
    }

    public function test_render_whole_episode_with_music_and_subtitles()
    {
        $disk = Storage::disk(config('videoaff.disk'));
        $disk->put('k.png', self::ffmpeg(['png', '-f', 'lavfi', '-i', 'color=c=orange:s=640x360', '-frames:v', '1']));
        $disk->put('clip.mp4', self::ffmpeg(['mp4', '-f', 'lavfi', '-i', 'color=c=blue:s=640x360:d=2:r=24', '-f', 'lavfi', '-i', 'sine=f=300:d=2', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest']));
        $disk->put('music.mp3', self::ffmpeg(['mp3', '-f', 'lavfi', '-i', 'sine=f=500:d=4']));
        $shots = $this->episode->shots()->get();
        foreach ($shots as $i => $shot) {
            $shot->update(['image_status' => 'ready', 'image_path' => 'k.png', 'duration_ms' => 1500, 'video_path' => $i < 2 ? 'clip.mp4' : null]);
        }
        $shots[0]->update(['audio_lines' => [['start_ms' => 300, 'end_ms' => 1100, 'character_id' => null]]]);
        $track = AudioTrack::factory()->for($this->user)->create(['path' => 'music.mp3']);

        Queue::fake();
        $this->actingAs($this->user)->post(route('series.episodes.render', $this->params()), [
            'title_card' => true, 'end_card' => true, 'burn_subtitles' => true, 'music_track_id' => $track->id, 'music_volume' => 15,
        ])->assertSessionHas('status', fn ($s) => str_contains($s, '4 shot chưa có video'));
        $episode = $this->episode->fresh();
        $this->assertSame('rendering', $episode->render_status);
        Queue::assertPushed(RenderEpisodeJob::class);

        (new RenderEpisodeJob($episode, $episode->render_options['run']))->handle();

        $episode->refresh();
        $this->assertSame('done', $episode->render_status, (string) $episode->render_error);
        $this->assertSame(100, $episode->render_progress);
        $disk->assertExists($episode->render_path);
        $this->assertStringContainsString('-->', $disk->get($episode->srt_path));
        $this->assertSame(1, $this->user->creditTransactions()->where('reference', 'like', 'episode-render:%')->count());

        $file = tempnam(sys_get_temp_dir(), 'ep').'.mp4';
        file_put_contents($file, $disk->get($episode->render_path));
        $probe = json_decode(Process::run(['ffprobe', '-v', 'error', '-show_entries', 'stream=codec_type,width,height:format=duration', '-of', 'json', $file])->output(), true);
        @unlink($file);
        $this->assertSame([1920, 1080], [$probe['streams'][0]['width'], $probe['streams'][0]['height']]);
        // Thẻ tiêu đề 3,5 s + 6 shot × 1,5 s + thẻ kết 3 s.
        $this->assertEqualsWithDelta(15.5, (float) $probe['format']['duration'], 0.3);
        $this->assertDirectoryDoesNotExist(RenderEpisodeJob::workDir($episode, $episode->render_options['run']));

        $this->actingAs($this->user)->get(route('series.episodes.show', $this->params()))
            ->assertInertia(fn (Assert $page) => $page->where('episode.render.status', 'done')->whereNot('episode.render.url', null)->has('musicTracks', 1));
        $this->actingAs($this->user)->get(route('series.episodes.render.download', $this->params()))->assertDownload();
        $this->actingAs($this->user)->get(route('series.episodes.subtitles', $this->params()))->assertDownload();
    }

    public function test_vertical_shorts_export_without_dialogue()
    {
        $disk = Storage::disk(config('videoaff.disk'));
        $disk->put('k.png', self::ffmpeg(['png', '-f', 'lavfi', '-i', 'color=c=orange:s=640x360', '-frames:v', '1']));
        $disk->put('clip.mp4', self::ffmpeg(['mp4', '-f', 'lavfi', '-i', 'color=c=blue:s=640x360:d=2:r=24', '-f', 'lavfi', '-i', 'sine=f=300:d=2', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest']));
        $disk->put('voice.wav', self::ffmpeg(['wav', '-f', 'lavfi', '-i', 'sine=f=400:d=1']));
        foreach ($this->episode->shots()->get() as $i => $shot) {
            $shot->update(['image_status' => 'ready', 'image_path' => 'k.png', 'duration_ms' => 1000, 'video_path' => $i < 2 ? 'clip.mp4' : null, 'audio_path' => 'voice.wav']);
        }

        foreach (['9:16_blur', '9:16_crop'] as $aspect) {
            Queue::fake();
            $this->actingAs($this->user)->post(route('series.episodes.render', $this->params()), [
                'aspect' => $aspect, 'dialogue' => false, 'burn_subtitles' => true, 'sound_effects' => false, 'title_card' => true, 'end_card' => false,
            ]);
            $episode = $this->episode->fresh();
            $this->assertSame($aspect, $episode->render_options['aspect']);
            $this->assertFalse($episode->render_options['dialogue']);
            (new RenderEpisodeJob($episode, $episode->render_options['run']))->handle();
            $episode->refresh();
            $this->assertSame('done', $episode->render_status, (string) $episode->render_error);

            $file = tempnam(sys_get_temp_dir(), 'ep').'.mp4';
            file_put_contents($file, $disk->get($episode->render_path));
            $probe = json_decode(Process::run(['ffprobe', '-v', 'error', '-show_entries', 'stream=width,height:format=duration', '-of', 'json', $file])->output(), true);
            $this->assertSame([1080, 1920], [$probe['streams'][0]['width'], $probe['streams'][0]['height']]);
            $this->assertEqualsWithDelta(9.5, (float) $probe['format']['duration'], 0.3);
            // Không lời: tiếng shot (clip lẫn lời đọc sẵn) bị thay bằng im lặng.
            $volume = Process::run(['ffmpeg', '-v', 'info', '-i', $file, '-af', 'volumedetect', '-f', 'null', '-'])->errorOutput();
            preg_match('/max_volume: (-?[\d.]+|-inf) dB/', $volume, $m);
            $this->assertTrue(($m[1] ?? '-inf') === '-inf' || (float) $m[1] < -60, $volume);
            @unlink($file);
        }
        $this->actingAs($this->user)->get(route('series.episodes.render.download', $this->params()))
            ->assertDownload(Str::slug($this->series->title).'-tap-'.$this->episode->number.'-doc-khong-loi.mp4');

        // Mỗi lần ghép thêm một bản: cả hai bản (nền mờ, cắt giữa) đều còn, file cũ không bị xoá.
        $renders = $this->episode->renders()->get();
        $this->assertCount(2, $renders);
        $this->assertSame(['9:16_crop', '9:16_blur'], $renders->pluck('options.aspect')->all());
        foreach ($renders as $render) {
            $disk->assertExists($render->path);
        }
        $this->assertSame($renders[0]->path, $this->episode->fresh()->render_path);
        $this->actingAs($this->user)->get(route('series.episodes.renders.download', [...$this->params(), 'render' => $renders[1]->id]))
            ->assertDownload($renders[1]->fileName().'.mp4');
        $this->actingAs($this->user)->get(route('series.episodes.show', $this->params()))
            ->assertInertia(fn (Assert $page) => $page->has('episode.renders', 2)->where('episode.renders.0.vertical', true)->where('episode.renders.0.has_srt', false));
        // Xoá bản mới nhất: tập trỏ về bản còn lại.
        $this->actingAs($this->user)->delete(route('series.episodes.renders.destroy', [...$this->params(), 'render' => $renders[0]->id]));
        $disk->assertMissing($renders[0]->path);
        $this->assertSame($renders[1]->path, $this->episode->fresh()->render_path);
        $other = User::factory()->create();
        $this->actingAs($other)->delete(route('series.episodes.renders.destroy', [...$this->params(), 'render' => $renders[1]->id]))->assertForbidden();
        $this->actingAs($this->user)->get(route('series.episodes.show', $this->params()))->assertInertia(fn (Assert $page) => $page->has('aspects', 3));
    }

    public function test_original_score_and_youtube_thumbnail()
    {
        // Dịch vụ phát nhạc giả: mỗi cue một tiếng sine dài đúng thời lượng xin.
        Http::fake(['*/music' => fn ($request) => Http::response(['cues' => array_map(fn ($c) => [
            'base64' => base64_encode(self::ffmpeg(['wav', '-f', 'lavfi', '-i', 'sine=f=440:d='.$c['seconds']])), 'duration' => $c['seconds'],
        ], $request->data()['cues'])])]);
        $disk = Storage::disk(config('videoaff.disk'));
        $disk->put('k.png', self::ffmpeg(['png', '-f', 'lavfi', '-i', 'color=c=orange:s=640x360', '-frames:v', '1']));
        $this->episode->shots()->update(['image_status' => 'ready', 'image_path' => 'k.png', 'duration_ms' => 1000]);

        $this->actingAs($this->user)->post(route('series.episodes.score', $this->params()))->assertSessionHas('status');
        $episode = $this->episode->fresh();
        $this->assertSame('ready', $episode->score_status, (string) $episode->score_error);
        $sequences = $episode->shots()->pluck('sequence')->unique()->count();
        $this->assertCount($sequences, $episode->score['sequences']);
        $this->assertCount($sequences, $episode->score['previews']['sequences']);
        $disk->assertExists($episode->score['previews']['title']);
        $this->actingAs($this->user)->get(route('series.episodes.show', $this->params()))
            ->assertInertia(fn (Assert $page) => $page->has('episode.score.cues', $sequences + 2)->whereNot('episode.score.cues.0.url', null));

        // Ghép không lời + nhạc riêng: vẫn có tiếng (nhạc), một lần gọi phát nhạc cho cả tập theo từng phân đoạn.
        Queue::fake();
        $this->actingAs($this->user)->post(route('series.episodes.render', $this->params()), ['original_score' => true, 'dialogue' => false, 'sound_effects' => false, 'music_volume' => 30]);
        $episode->refresh();
        (new RenderEpisodeJob($episode, $episode->render_options['run']))->handle();
        $episode->refresh();
        $this->assertSame('done', $episode->render_status, (string) $episode->render_error);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/music') && count($request->data()['cues']) === $sequences + 2);
        $file = tempnam(sys_get_temp_dir(), 'ep').'.mp4';
        file_put_contents($file, $disk->get($episode->render_path));
        preg_match('/max_volume: (-?[\d.]+) dB/', Process::run(['ffmpeg', '-v', 'info', '-i', $file, '-af', 'volumedetect', '-f', 'null', '-'])->errorOutput(), $m);
        @unlink($file);
        $this->assertGreaterThan(-50, (float) ($m[1] ?? -99));

        // Ảnh bìa YouTube 1280×720 tạo cùng bản xuất; tạo lại với chữ khác.
        $render = $episode->renders()->first();
        $this->assertNotNull($render->thumbnail_path);
        $image = imagecreatefromstring($disk->get($render->thumbnail_path));
        $this->assertSame([1280, 720], [imagesx($image), imagesy($image)]);
        $old = $render->thumbnail_path;
        $shot = $episode->shots()->skip(2)->first();
        $this->actingAs($this->user)->post(route('series.episodes.renders.thumbnail', [...$this->params(), 'render' => $render->id]), ['shot_id' => $shot->id, 'text' => 'WOW!'])
            ->assertSessionHas('status', 'Đã tạo lại ảnh bìa.');
        $render->refresh();
        $this->assertSame(['shot_id' => $shot->id, 'text' => 'WOW!', 'art' => false], $render->thumbnail_options);
        $disk->assertMissing($old);
        $this->actingAs($this->user)->get(route('series.episodes.renders.thumbnail.download', [...$this->params(), 'render' => $render->id]))->assertDownload();
    }

    /** Kho âm thanh nhỏ cho test (đúng các id mà Claude giả chọn). */
    private function fakeLibrary(): void
    {
        $disk = Storage::disk(config('videoaff.disk'));
        $entries = [];
        foreach (['jingles_steel_1' => 'jingle', 'cartoon_whoosh_short' => 'transition', 'cartoon_pop' => 'cartoon', 'cartoon_tada' => 'cartoon'] as $id => $group) {
            $disk->put("sounds/library/{$id}.wav", self::ffmpeg(['wav', '-f', 'lavfi', '-i', 'sine=f=800:d=0.4']));
            $entries[] = ['id' => $id, 'group' => $group, 'description' => $id, 'files' => ["sounds/library/{$id}.wav"], 'duration_ms' => 400, 'source' => 'test'];
        }
        $disk->put(SoundLibrary::CATALOG, json_encode($entries));
    }

    public function test_claude_picks_sound_effects_and_render_mixes_them()
    {
        $this->actingAs($this->user)->post(route('series.episodes.sounds', $this->params()))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'kho âm thanh'));
        $this->fakeLibrary();

        $this->actingAs($this->user)->post(route('series.episodes.sounds', $this->params()))->assertSessionHas('status');
        $episode = $this->episode->fresh();
        $this->assertSame('ready', $episode->sound_status);
        $places = array_column($episode->sound_plan, 'place');
        $this->assertSame('title', $places[0]);
        $this->assertSame('end', end($places));
        $this->assertContains('cartoon_whoosh_short', array_column($episode->sound_plan, 'sound'));
        // Âm thanh không có trong kho / shot không tồn tại thì bỏ; thời điểm kẹp trong shot.
        $shots = $episode->shots()->get()->all();
        $clean = SoundPlanner::normalize([
            ['place' => 'shot', 'shot' => 1, 'sound' => 'nope', 'at' => 1, 'volume' => 0.5],
            ['place' => 'shot', 'shot' => 99, 'sound' => 'cartoon_pop', 'at' => 1, 'volume' => 0.5],
            ['place' => 'shot', 'shot' => 1, 'sound' => 'cartoon_pop', 'at' => 999, 'volume' => 5],
        ], $shots, SoundLibrary::byId());
        $this->assertCount(1, $clean);
        $this->assertSame($shots[0]->duration_ms - 100, $clean[0]['at_ms']);
        $this->assertSame(1.0, $clean[0]['volume']);

        $count = count($episode->sound_plan);
        $this->actingAs($this->user)->get(route('series.episodes.show', $this->params()))
            ->assertInertia(fn (Assert $page) => $page->has('episode.sounds.items', $count)->where('episode.sounds.library_ready', true));
        $this->actingAs($this->user)->delete(route('series.episodes.sounds.destroy', [...$this->params(), 'index' => 0]));
        $this->assertCount($count - 1, $this->episode->fresh()->sound_plan);

        // Ghép tập có hiệu ứng (không nhạc nền, không phụ đề chèn hình): vẫn đủ thời lượng.
        $disk = Storage::disk(config('videoaff.disk'));
        $disk->put('k.png', self::ffmpeg(['png', '-f', 'lavfi', '-i', 'color=c=orange:s=640x360', '-frames:v', '1']));
        $this->episode->shots()->update(['image_status' => 'ready', 'image_path' => 'k.png', 'duration_ms' => 1000]);
        Queue::fake();
        $disk->put('music.mp3', self::ffmpeg(['mp3', '-f', 'lavfi', '-i', 'sine=f=500:d=4']));
        $track = AudioTrack::factory()->for($this->user)->create(['path' => 'music.mp3']);
        // Hiệu ứng + nhạc nền (nhạc tự nhỏ khi có lời).
        $this->actingAs($this->user)->post(route('series.episodes.render', $this->params()), ['sound_effects' => true, 'title_card' => false, 'end_card' => true, 'music_track_id' => $track->id]);
        $episode = $this->episode->fresh();
        (new RenderEpisodeJob($episode, $episode->render_options['run']))->handle();
        $episode->refresh();
        $this->assertSame('done', $episode->render_status, (string) $episode->render_error);
        $file = tempnam(sys_get_temp_dir(), 'ep').'.mp4';
        file_put_contents($file, $disk->get($episode->render_path));
        $this->assertEqualsWithDelta(9.0, (float) trim(Process::run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $file])->output()), 0.3);
        @unlink($file);
    }

    public function test_one_button_picks_sounds_then_renders()
    {
        $this->fakeLibrary();
        $disk = Storage::disk(config('videoaff.disk'));
        $disk->put('k.png', self::ffmpeg(['png', '-f', 'lavfi', '-i', 'color=c=orange:s=640x360', '-frames:v', '1']));
        $this->episode->shots()->update(['image_status' => 'ready', 'image_path' => 'k.png', 'duration_ms' => 1000]);

        Queue::fake();
        $this->actingAs($this->user)->post(route('series.episodes.render', $this->params()), ['sound_effects' => true])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'chọn âm thanh'));
        $episode = $this->episode->fresh();
        $this->assertSame('planning', $episode->sound_status);
        $this->assertTrue($episode->render_options['plan_sounds']);

        (new RenderEpisodeJob($episode, $episode->render_options['run']))->handle();
        $episode->refresh();
        $this->assertSame('ready', $episode->sound_status);
        $this->assertNotEmpty($episode->sound_plan);
        $this->assertSame('done', $episode->render_status, (string) $episode->render_error);

        // Đã có danh sách: ghép lại không gọi Claude nữa.
        $this->actingAs($this->user)->post(route('series.episodes.render', $this->params()), ['sound_effects' => true]);
        $this->assertFalse($this->episode->fresh()->render_options['plan_sounds']);
    }

    public function test_render_needs_keyframes_and_owner()
    {
        $this->actingAs($this->user)->post(route('series.episodes.render', $this->params()))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'chưa có khung hình'));
        $this->assertNull($this->episode->fresh()->render_status);
        $this->actingAs(User::factory()->create())->post(route('series.episodes.render', $this->params()))->assertForbidden();
    }

    public function test_ai_titles_and_ai_drawn_thumbnail()
    {
        $disk = Storage::disk(config('videoaff.disk'));
        $disk->put('k.png', self::ffmpeg(['png', '-f', 'lavfi', '-i', 'color=c=orange:s=640x360', '-frames:v', '1']));
        $this->episode->shots()->update(['image_status' => 'ready', 'image_path' => 'k.png', 'duration_ms' => 1000]);
        foreach ($this->series->characters as $character) {
            $character->update(['main_image_path' => 'k.png']);
        }

        // Claude gợi ý tiêu đề (kèm chữ ảnh bìa), bấm "Dùng" đổi tên tập.
        $this->actingAs($this->user)->post(route('series.episodes.titles', $this->params()))->assertSessionHas('status');
        $episode = $this->episode->fresh();
        $this->assertSame('ready', $episode->title_status, (string) $episode->title_error);
        $this->assertNotEmpty($episode->title_ideas[0]['thumbnail_text']);
        $this->actingAs($this->user)->post(route('series.episodes.titles.use', $this->params()), ['index' => 1]);
        $this->assertSame($episode->title_ideas[1]['title'], $episode->fresh()->title);
        $this->actingAs($this->user)->post(route('series.episodes.titles.use', $this->params()), ['index' => 9])->assertNotFound();

        // Muse vẽ riêng ảnh nền ảnh bìa (theo ảnh chính nhân vật), xong in chữ lên, trừ Credits một ảnh.
        $disk->put('series/r.mp4', 'x');
        $render = $episode->renders()->create(['lang' => 'en', 'options' => ['aspect' => '16:9'], 'path' => 'series/r.mp4', 'duration_ms' => 1000]);
        $this->assertStringContainsString('No text', Thumbnail::artPrompt($render));
        Queue::fake();
        $this->actingAs($this->user)->post(route('series.episodes.renders.thumbnail-art', [...$this->params(), 'render' => $render->id]), ['text' => 'UH OH!'])
            ->assertSessionHas('status');
        Queue::assertPushed(GenerateThumbnailArtJob::class, fn ($job) => $job->text === 'UH OH!');
        $this->assertSame('pending', $render->fresh()->art_status);
        $job = new GenerateThumbnailArtJob($render, Thumbnail::artPrompt($render), 'UH OH!');
        $job->handle();
        $job->handle();
        $render->refresh();
        $this->assertSame('ready', $render->art_status, (string) $render->art_error);
        $disk->assertExists($render->art_path);
        $this->assertTrue($render->thumbnail_options['art']);
        $this->assertSame('UH OH!', $render->thumbnail_options['text']);
        $this->assertTrue($this->user->creditTransactions()->where('reference', 'like', "thumbnail-art:{$render->id}:%")->exists());
        $image = imagecreatefromstring($disk->get($render->thumbnail_path));
        $this->assertSame([1280, 720], [imagesx($image), imagesy($image)]);
        $this->actingAs($this->user)->get(route('series.episodes.show', $this->params()))
            ->assertInertia(fn (Assert $page) => $page->where('episode.renders.0.thumbnail_art', true)->whereNot('episode.renders.0.art_url', null)->has('episode.titles.ideas', 2));
    }

    public function test_ai_thumbnail_without_text_suggests_titles_first()
    {
        $disk = Storage::disk(config('videoaff.disk'));
        $disk->put('k.png', self::ffmpeg(['png', '-f', 'lavfi', '-i', 'color=c=orange:s=640x360', '-frames:v', '1']));
        $this->episode->shots()->update(['image_status' => 'ready', 'image_path' => 'k.png', 'duration_ms' => 1000]);
        $this->series->characters()->update(['main_image_path' => 'k.png']);
        $this->episode->update(['title' => '[Episode Title]', 'title_ideas' => null]);
        $disk->put('series/r.mp4', 'x');
        $render = $this->episode->renders()->create(['lang' => 'en', 'options' => ['aspect' => '9:16'], 'path' => 'series/r.mp4', 'duration_ms' => 1000]);

        Queue::fake();
        $this->actingAs($this->user)->post(route('series.episodes.renders.thumbnail-art', [...$this->params(), 'render' => $render->id]), ['text' => '[Episode Title]'])
            ->assertSessionHas('status', fn ($status) => str_contains($status, 'gợi ý tiêu đề'));
        $job = Queue::pushed(GenerateThumbnailArtJob::class)->first();
        $this->assertStringContainsString('"[Episode Title]"', $job->prompt);
        $job->handle();
        $job->handle();

        $episode = $this->episode->fresh();
        $this->assertNotEmpty($episode->title_ideas);
        $this->assertSame($episode->title_ideas[0]['title'], $episode->title);
        $this->assertStringContainsString("\"{$episode->title}\"", $job->prompt);
        $render->refresh();
        $this->assertSame('ready', $render->art_status, (string) $render->art_error);
        $this->assertSame($episode->title_ideas[0]['thumbnail_text'], $render->thumbnail_options['text']);
        $image = imagecreatefromstring($disk->get($render->thumbnail_path));
        $this->assertSame([1080, 1920], [imagesx($image), imagesy($image)]);
    }

    public function test_placeholder_episode_title_is_replaced_before_rendering()
    {
        $disk = Storage::disk(config('videoaff.disk'));
        $disk->put('k.png', self::ffmpeg(['png', '-f', 'lavfi', '-i', 'color=c=orange:s=640x360', '-frames:v', '1']));
        $this->episode->shots()->update(['image_status' => 'ready', 'image_path' => 'k.png', 'duration_ms' => 1000]);
        $this->assertTrue(EpisodeTitler::placeholder('[Episode Title]'));
        $this->assertFalse(EpisodeTitler::placeholder('The Last Cookie'));

        // Có tiêu đề trong gói đăng bài: dùng luôn, không gọi Claude.
        $this->episode->update(['title' => '[Episode Title]', 'package' => ['title' => 'The Jar Nobody Could Open']]);
        $this->assertTrue(EpisodeTitler::ensure($this->episode->fresh()));
        $this->assertSame('The Jar Nobody Could Open', $this->episode->fresh()->title);

        // Không có gì: lúc ghép Claude đặt tên, thẻ tiêu đề không còn chữ tạm.
        $this->episode->fresh()->update(['title' => '[Episode Title]', 'package' => null, 'title_ideas' => null]);
        Queue::fake();
        $this->actingAs($this->user)->post(route('series.episodes.render', $this->params()), ['title_card' => true, 'sound_effects' => false]);
        $episode = $this->episode->fresh();
        (new RenderEpisodeJob($episode, $episode->render_options['run']))->handle();
        $episode->refresh();
        $this->assertSame('done', $episode->render_status, (string) $episode->render_error);
        $this->assertFalse(EpisodeTitler::placeholder($episode->title));
        $this->assertSame($episode->title_ideas[0]['title'], $episode->title);
    }
}
