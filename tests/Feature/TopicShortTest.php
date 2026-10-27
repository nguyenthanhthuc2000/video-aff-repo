<?php

namespace Tests\Feature;

use App\Jobs\FindTopicSceneImageJob;
use App\Jobs\GenerateTopicSceneImageJob;
use App\Jobs\RenderTopicShortJob;
use App\Jobs\WriteTopicShortJob;
use App\Models\TopicShort;
use App\Models\TopicShortScene;
use App\Models\User;
use App\Services\Film\FakeFilmImageGenerator;
use App\Services\Image\GeneratedImage;
use App\Services\Image\ImageGeneratorException;
use App\Services\TopicShort\RemoteImage;
use App\Services\TopicShort\ShortVideo;
use App\Services\TopicShort\ShortWriter;
use App\Services\TopicShort\WikiImageSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Shorts theo chủ đề: Claude viết lời kể, ảnh wiki / Muse / tải lên, giọng AI, dựng video 9:16. */
class TopicShortTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /** Commons trả rỗng (giả lập không tìm thấy ở cả hai wiki). */
    private bool $commonsEmpty = false;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('videoaff.disk'));
        $this->user = User::factory()->create();
        Http::fake([
            'dragonball.fandom.com/*' => fn ($request) => str_contains($request['gsrsearch'], 'blocked') ? Http::response('Forbidden', 403) : Http::response(str_contains($request['gsrsearch'], 'nothing')
                ? ['batchcomplete' => ''] : self::wikiResult('https://static.wikia.nocookie.net/dragonball/images/a/a1/Goku_SSJ.png')),
            'commons.wikimedia.org/*' => fn () => Http::response($this->commonsEmpty ? ['batchcomplete' => ''] : self::wikiResult('https://upload.wikimedia.org/wikipedia/commons/a/a1/Namek.png')),
            'static.wikia.nocookie.net/*' => Http::response(self::png(), 200, ['Content-Type' => 'image/png']),
            'upload.wikimedia.org/*' => Http::response(self::png(), 200, ['Content-Type' => 'image/png']),
            '*/tts' => fn () => Http::response(['base64' => base64_encode(self::wav(3)), 'duration' => 3.0, 'lines' => [
                ['start' => 0.3, 'end' => 1.0], ['start' => 1.2, 'end' => 2.0], ['start' => 2.2, 'end' => 2.9],
            ]]),
        ]);
    }

    private static function wikiResult(string $url): array
    {
        return ['query' => ['pages' => [
            '2' => ['index' => 2, 'title' => 'File:Tiny.png', 'imageinfo' => [['url' => $url.'?tiny', 'width' => 100, 'height' => 100, 'mime' => 'image/png', 'descriptionurl' => 'https://x/tiny']]],
            '1' => ['index' => 1, 'title' => 'File:Big.png', 'imageinfo' => [['url' => $url, 'thumburl' => $url, 'width' => 1280, 'height' => 720, 'mime' => 'image/png', 'descriptionurl' => 'https://x/big']]],
            '3' => ['index' => 3, 'title' => 'File:Evil.png', 'imageinfo' => [['url' => 'https://evil.example.com/a.png', 'width' => 1280, 'height' => 720, 'mime' => 'image/png']]],
        ]]];
    }

    private static function png(): string
    {
        $image = imagecreatetruecolor(64, 36);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 160, 0));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private static function wav(float $seconds): string
    {
        $data = str_repeat("\0\0", (int) (24000 * $seconds));

        return 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16).'data'.pack('V', strlen($data)).$data;
    }

    public function test_create_short_writes_script_and_finds_wiki_images()
    {
        Queue::fake();
        $this->actingAs($this->user)->post(route('topic-shorts.store'), [
            'topic' => 'Lần đầu Goku biến thành Super Saiyan', 'style' => 'versus', 'image_style' => 'manga_color', 'language' => 'vi', 'target_seconds' => 45, 'wiki' => 'dragonball',
        ])->assertRedirect();
        $short = $this->user->topicShorts()->sole();
        $this->assertSame('writing', $short->status);
        $this->assertSame('versus', $short->style);
        $this->assertSame(array_keys(TopicShort::STYLES), array_keys(ShortWriter::FORMATS));
        $this->actingAs($this->user)->post(route('topic-shorts.store'), ['topic' => 'Goku đấu Sukuna', 'style' => 'bogus', 'image_style' => 'manga', 'language' => 'vi', 'target_seconds' => 45, 'wiki' => 'commons'])
            ->assertSessionHasErrors('style');
        Queue::assertPushed(WriteTopicShortJob::class);

        (new WriteTopicShortJob($short))->handle();
        $short->refresh();
        $this->assertSame('ready', $short->status, (string) $short->error);
        $this->assertNotEmpty($short->title);
        $scenes = $short->scenes()->get();
        $this->assertGreaterThanOrEqual(2, $scenes->count());
        // Không tự tìm wiki: thư viện nhân vật chưa có ảnh thì cảnh báo cách sửa.
        $this->assertSame('failed', $scenes[0]->image_status);
        $this->assertStringContainsString('Vẽ khung còn thiếu', $scenes[0]->image_error);
        $this->assertNotSame([], $scenes[0]->panels);
        Queue::assertNotPushed(FindTopicSceneImageJob::class);

        // Vẫn tìm wiki được khi bấm Tìm.
        $scenes[0]->update(['image_status' => 'searching']);
        (new FindTopicSceneImageJob($scenes[0]))->handle(app(WikiImageSearch::class));
        $scene = $scenes[0]->fresh();
        $this->assertSame('ready', $scene->image_status, (string) $scene->image_error);
        $this->assertSame('web', $scene->image_source);
        $this->assertSame('https://static.wikia.nocookie.net/dragonball/images/a/a1/Goku_SSJ.png', $scene->image_url);
        // Ảnh quá nhỏ và ảnh ngoài danh sách host bị bỏ.
        $this->assertCount(1, $scene->candidates);
        Storage::disk(config('videoaff.disk'))->assertExists($scene->image_path);

        $this->actingAs($this->user)->get(route('topic-shorts.show', $short))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('topic-shorts/show')->where('short.status', 'ready')->where('short.scenes.0.image_status', 'ready')->where('short.all_images_ready', false));
        $this->actingAs($this->user)->get(route('topic-shorts.index'))->assertInertia(fn (Assert $page) => $page->has('shorts', 1));
    }

    public function test_search_falls_back_to_commons_and_reports_nothing_found()
    {
        $scene = TopicShortScene::factory()->create(['image_query' => 'nothing here', 'image_status' => 'searching']);
        (new FindTopicSceneImageJob($scene))->handle(app(WikiImageSearch::class));
        $scene->refresh();
        $this->assertSame('ready', $scene->image_status);
        $this->assertStringStartsWith('https://upload.wikimedia.org/', $scene->image_url);

        // Wiki chính chặn (403): vẫn lấy được ảnh từ Commons.
        $blocked = TopicShortScene::factory()->create(['image_query' => 'blocked query', 'image_status' => 'searching']);
        (new FindTopicSceneImageJob($blocked))->handle(app(WikiImageSearch::class));
        $this->assertSame('ready', $blocked->fresh()->image_status);
        $this->assertStringStartsWith('https://upload.wikimedia.org/', $blocked->fresh()->image_url);
        Http::assertSent(fn ($request) => str_starts_with($request->header('User-Agent')[0] ?? '', 'VideoAff/1.0 (https://'));

        // Không wiki nào có ảnh: Muse vẽ theo prompt; không có prompt thì báo lỗi.
        $this->commonsEmpty = true;
        Queue::fake();
        $drawn = TopicShortScene::factory()->create(['image_query' => 'nothing again', 'image_status' => 'searching']);
        (new FindTopicSceneImageJob($drawn))->handle(app(WikiImageSearch::class));
        $this->assertSame('pending', $drawn->fresh()->image_status);
        Queue::assertPushed(GenerateTopicSceneImageJob::class);
        $other = TopicShortScene::factory()->create(['image_query' => 'nothing again', 'image_prompt' => '', 'image_status' => 'searching']);
        (new FindTopicSceneImageJob($other))->handle(app(WikiImageSearch::class));
        $other->refresh();
        $this->assertSame('failed', $other->image_status);
        $this->assertStringContainsString('Không tìm thấy ảnh', $other->image_error);
    }

    public function test_search_queries_drop_words_from_the_end()
    {
        $this->assertSame(['Goku Super Saiyan Namek', 'Goku Super Saiyan', 'Goku Super'], FindTopicSceneImageJob::queries(' Goku  Super Saiyan Namek '));
        $this->assertSame(['Goku'], FindTopicSceneImageJob::queries('Goku'));
        $this->assertSame([], FindTopicSceneImageJob::queries(''));
        $this->assertSame(['Goku Frieza manga', 'Goku Frieza'], FindTopicSceneImageJob::queries('Goku Frieza', true));
    }

    public function test_remote_image_only_allows_wiki_hosts()
    {
        $this->assertTrue(RemoteImage::allowed('https://static.wikia.nocookie.net/a.png'));
        $this->assertTrue(RemoteImage::allowed('https://upload.wikimedia.org/a.png'));
        $this->assertTrue(RemoteImage::allowed('https://thumb.wikimedia.org/a.png'));
        $this->assertFalse(RemoteImage::allowed('http://upload.wikimedia.org/a.png'));
        $this->assertFalse(RemoteImage::allowed('https://upload.wikimedia.org.evil.com/a.png'));
        $this->assertFalse(RemoteImage::allowed('https://user@upload.wikimedia.org/a.png'));
        $this->assertFalse(RemoteImage::allowed('https://127.0.0.1/a.png'));
    }

    public function test_scene_image_actions_search_pick_muse_upload()
    {
        Queue::fake();
        $short = TopicShort::factory()->for($this->user)->create(['status' => 'ready']);
        $scene = TopicShortScene::factory()->for($short)->withImage()->create([
            'candidates' => [['url' => 'https://static.wikia.nocookie.net/dragonball/images/b/b2/Frieza.png', 'title' => 'Frieza']],
        ]);

        $this->actingAs($this->user)->post(route('topic-short-scenes.search', $scene), ['query' => 'Frieza Namek'])->assertSessionHas('status');
        $this->assertSame('Frieza Namek', $scene->fresh()->image_query);
        Queue::assertPushed(FindTopicSceneImageJob::class);
        // Đang tìm thì không chạy thêm.
        $this->actingAs($this->user)->post(route('topic-short-scenes.muse', $scene))->assertSessionHas('status', 'Ảnh cảnh này đang được tạo.');
        $scene->refresh()->update(['image_status' => 'ready']);

        // Chỉ chọn được ảnh trong kết quả tìm.
        $this->actingAs($this->user)->post(route('topic-short-scenes.pick', $scene), ['url' => 'https://evil.example.com/a.png'])->assertSessionHasErrors('url');
        $this->actingAs($this->user)->post(route('topic-short-scenes.pick', $scene), ['url' => 'https://static.wikia.nocookie.net/dragonball/images/b/b2/Frieza.png'])->assertSessionHasNoErrors();
        $this->assertSame('https://static.wikia.nocookie.net/dragonball/images/b/b2/Frieza.png', $scene->fresh()->image_url);

        $this->actingAs($this->user)->post(route('topic-short-scenes.muse', $scene), ['prompt' => 'A spiky-haired warrior with golden aura'])->assertSessionHas('status');
        $scene->refresh();
        $this->assertSame('pending', $scene->image_status);
        Queue::assertPushed(GenerateTopicSceneImageJob::class);
        $this->assertStringContainsString('golden aura', GenerateTopicSceneImageJob::prompt($scene));
        $this->assertStringContainsString('9:16', GenerateTopicSceneImageJob::prompt($scene));

        // Muse (driver fake) vẽ: lần đầu gửi yêu cầu, lần sau lấy ảnh và trừ Credits.
        (new GenerateTopicSceneImageJob($scene))->handle();
        $this->assertNotNull($scene->fresh()->request_id);
        (new GenerateTopicSceneImageJob($scene))->handle();
        $scene->refresh();
        $this->assertSame('ready', $scene->image_status, (string) $scene->image_error);
        $this->assertSame('muse', $scene->image_source);
        $this->assertSame(1, $this->user->creditTransactions()->where('reference', 'like', 'topic-scene-image:%')->count());

        // Bấm Muse vẽ không gõ prompt: Claude (mẫu) viết prompt mới theo lời kể hiện tại của cảnh trước khi vẽ.
        $scene->update(['narration' => 'Frieza mỉm cười trên Namek.', 'characters' => ['Frieza']]);
        $this->actingAs($this->user)->post(route('topic-short-scenes.muse', $scene))->assertSessionHas('status');
        (new GenerateTopicSceneImageJob($scene))->handle();
        $scene->refresh();
        $this->assertSame('Frieza — Frieza mỉm cười trên Namek.', $scene->image_prompt);
        $this->assertNotNull($scene->request_id);
        (new GenerateTopicSceneImageJob($scene))->handle();
        $this->assertSame('ready', $scene->fresh()->image_status);

        $this->actingAs($this->user)->post(route('topic-short-scenes.upload', $scene), ['image' => UploadedFile::fake()->image('mine.png', 720, 1280)])->assertSessionHasNoErrors();
        $scene->refresh();
        $this->assertSame('upload', $scene->image_source);
        Storage::disk(config('videoaff.disk'))->assertExists($scene->image_path);
    }

    public function test_muse_refusals_retry_once_without_names_then_stop_and_keep_old_image()
    {
        $calls = new \ArrayObject;
        $this->app->bind(FakeFilmImageGenerator::class, fn () => new class($calls) extends FakeFilmImageGenerator
        {
            public function __construct(private \ArrayObject $calls) {}

            public function start(array $referencePaths, string $prompt): string
            {
                $this->calls[] = [$referencePaths, $prompt];

                return parent::start($referencePaths, $prompt);
            }

            public function poll(string $code, array $referencePaths): ?GeneratedImage
            {
                throw ImageGeneratorException::rejected('AI từ chối tạo yêu cầu này.', 'copyrighted character');
            }
        });
        Queue::fake();
        $short = TopicShort::factory()->for($this->user)->create(['status' => 'ready', 'characters' => [['name' => 'Beerus', 'look' => 'purple cat god']]]);
        $scene = TopicShortScene::factory()->for($short)->withImage()->create(['characters' => ['Beerus'], 'image_prompt' => 'Beerus yawns']);
        $old = $scene->image_path;

        $this->actingAs($this->user)->post(route('topic-short-scenes.muse', $scene), ['prompt' => 'Beerus yawns on a throne'])->assertSessionHas('status');
        $job = new GenerateTopicSceneImageJob($scene);
        $this->assertSame('images', $job->queue);
        foreach (range(1, 3) as $_) {
            (new GenerateTopicSceneImageJob($scene))->withFakeQueueInteractions()->handle();
        }
        $last = (new GenerateTopicSceneImageJob($scene))->withFakeQueueInteractions();
        $last->handle();
        $last->assertFailed();
        $last->failed($last->job->failedWith);
        // Lần 1 có tên; bị từ chối thì lần 2 bỏ tên nhân vật và ảnh mẫu; bị từ chối nữa thì dừng.
        $this->assertCount(2, $calls);
        $this->assertStringContainsString('Beerus', $calls[0][1]);
        $this->assertStringNotContainsString('Beerus', $calls[1][1]);
        $this->assertSame([], $calls[1][0]);
        $scene->refresh();
        $this->assertSame('ready', $scene->image_status);
        $this->assertSame($old, $scene->image_path);
        $this->assertStringContainsString('Muse từ chối', (string) $scene->image_error);

        // Bấm vẽ lại: đếm lại từ đầu, lại thử bản có tên trước.
        $this->actingAs($this->user)->post(route('topic-short-scenes.muse', $scene), ['prompt' => 'Beerus naps'])->assertSessionHas('status');
        (new GenerateTopicSceneImageJob($scene))->handle();
        $this->assertCount(3, $calls);
        $this->assertStringContainsString('Beerus', $calls[2][1]);
    }

    public function test_update_script_and_other_users_are_blocked()
    {
        $short = TopicShort::factory()->for($this->user)->create(['status' => 'ready']);
        $scene = TopicShortScene::factory()->for($short)->create();
        $this->actingAs($this->user)->put(route('topic-shorts.update', $short), [
            'title' => 'Super Saiyan!', 'caption' => 'Cơn giận bùng nổ',
            'scenes' => [['id' => $scene->id, 'narration' => 'Lời mới.', 'on_screen_text' => 'BÙNG NỔ', 'image_query' => 'Goku', 'image_prompt' => 'warrior']],
        ])->assertSessionHasNoErrors();
        $this->assertSame('Lời mới.', $scene->fresh()->narration);
        $this->assertSame('Cơn giận bùng nổ', $short->fresh()->content['caption']);

        $other = User::factory()->create();
        $this->actingAs($other)->get(route('topic-shorts.show', $short))->assertForbidden();
        $this->actingAs($other)->post(route('topic-short-scenes.muse', $scene))->assertForbidden();
        $this->actingAs($other)->delete(route('topic-shorts.destroy', $short))->assertForbidden();
    }

    public function test_render_needs_all_images_then_builds_video_with_voice()
    {
        Queue::fake();
        $short = TopicShort::factory()->for($this->user)->create(['status' => 'ready', 'title' => 'Goku hoá Super Saiyan', 'language' => 'vi']);
        Storage::disk(config('videoaff.disk'))->put('topic-shorts/test/scene.png', self::png());
        foreach ([1, 2, 3] as $position) {
            TopicShortScene::factory()->for($short)->withImage()->create(['position' => $position, 'narration' => "Câu kể số {$position}, Goku nổi giận.", 'on_screen_text' => "CẢNH {$position}"]);
        }
        $short->scenes()->where('position', 3)->update(['image_status' => 'failed', 'image_path' => null]);
        $this->actingAs($this->user)->post(route('topic-shorts.render', $short))->assertSessionHas('status', 'Mọi cảnh cần có ảnh trước khi dựng video.');
        $short->scenes()->where('position', 3)->update(['image_status' => 'ready', 'image_path' => 'topic-shorts/test/scene.png']);

        $this->actingAs($this->user)->post(route('topic-shorts.render', $short))->assertSessionHas('status');
        $short->refresh();
        $this->assertSame('rendering', $short->video_status);
        Queue::assertPushed(RenderTopicShortJob::class);

        (new RenderTopicShortJob($short, $short->video_options['run']))->handle();
        $short->refresh();
        $this->assertSame('done', $short->video_status, (string) $short->video_error);
        $this->assertGreaterThanOrEqual(3000, $short->video_ms);
        Storage::disk(config('videoaff.disk'))->assertExists($short->video_path);
        $this->assertSame(1, $this->user->creditTransactions()->where('reference', 'like', 'topic-short:%')->count());
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/tts') && ($request['lines'][0]['lang'] ?? null) === 'vi');
        $this->actingAs($this->user)->get(route('topic-shorts.download', $short))->assertDownload();
    }

    public function test_scene_voice_upload_replaces_machine_voice_for_that_scene()
    {
        Queue::fake();
        $short = TopicShort::factory()->for($this->user)->create(['status' => 'ready', 'language' => 'vi']);
        Storage::disk(config('videoaff.disk'))->put('topic-shorts/test/scene.png', self::png());
        foreach ([1, 2, 3] as $position) {
            TopicShortScene::factory()->for($short)->withImage()->create(['position' => $position, 'narration' => "Câu kể số {$position}."]);
        }
        $scene = $short->scenes()->where('position', 2)->first();
        $mp3 = sys_get_temp_dir().'/voice-test.mp3';
        Process::run(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=300:duration=1.5', $mp3])->throw();

        $this->actingAs(User::factory()->create())->post(route('topic-short-scenes.voice', $scene), ['voice' => new UploadedFile($mp3, 'canh2.mp3', 'audio/mpeg', null, true)])->assertForbidden();
        $this->actingAs($this->user)->post(route('topic-short-scenes.voice', $scene), ['voice' => UploadedFile::fake()->create('x.mp3', 10, 'audio/mpeg')])->assertSessionHasErrors('voice');
        $this->actingAs($this->user)->post(route('topic-short-scenes.voice', $scene), ['voice' => new UploadedFile($mp3, 'canh2.mp3', 'audio/mpeg', null, true)])->assertSessionHasNoErrors();
        $scene->refresh();
        $this->assertEqualsWithDelta(1500, $scene->voice_ms, 100);
        Storage::disk(config('videoaff.disk'))->assertExists($scene->voice_path);
        $this->actingAs($this->user)->get(route('topic-shorts.show', $short))->assertInertia(fn (Assert $page) => $page->where('short.scenes.1.voice_ms', $scene->voice_ms));

        $this->actingAs($this->user)->post(route('topic-shorts.render', $short))->assertSessionHas('status');
        $short->refresh();
        (new RenderTopicShortJob($short, $short->video_options['run']))->handle();
        $short->refresh();
        $this->assertSame('done', $short->video_status, (string) $short->video_error);
        // Máy chỉ đọc cảnh 1 và 3; cảnh 2 dùng giọng tự thu: 0,3 + 0,7 + 0,25 + 1,5 + 0,25 + 0,8 + 0,8 giây.
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/tts') && array_column($request['lines'], 'text') === ['Câu kể số 1.', 'Câu kể số 3.']);
        $this->assertEqualsWithDelta(4500, $short->video_ms, 150);

        $path = $scene->voice_path;
        $this->actingAs($this->user)->delete(route('topic-short-scenes.voice.destroy', $scene))->assertSessionHasNoErrors();
        $this->assertNull($scene->fresh()->voice_path);
        Storage::disk(config('videoaff.disk'))->assertMissing($path);
    }

    public function test_characters_get_reference_images_and_named_prompts()
    {
        Queue::fake();
        $short = TopicShort::factory()->for($this->user)->create(['status' => 'writing']);
        (new WriteTopicShortJob($short))->handle();
        $short->refresh();
        $this->assertSame(['Goku', 'Frieza'], array_column($short->characters, 'name'));
        $this->assertNotEmpty($short->art_style);
        $last = $short->scenes()->get()->last();
        $this->assertSame(['Goku', 'Frieza'], $last->characters);

        // Ảnh mẫu nhân vật gửi kèm cho Muse; prompt ghi tên, mô tả và phong cách phim.
        $this->actingAs($this->user)->post(route('topic-shorts.character-image', [$short, 0]), ['image' => UploadedFile::fake()->image('goku.png', 600, 800)])->assertSessionHasNoErrors();
        $short->refresh();
        Storage::disk(config('videoaff.disk'))->assertExists($short->characters[0]['image_path']);
        $prompt = GenerateTopicSceneImageJob::prompt($last);
        $this->assertStringContainsString('Goku — looks exactly like attached reference image 1', $prompt);
        $this->assertStringContainsString('- Frieza: final form', $prompt);
        $this->assertStringContainsString('Dragon Ball, Akira Toriyama', $prompt);
        $this->assertStringContainsString('Black-and-white manga panel', $prompt);
        $this->assertSame([$short->characters[0]['image_path']], GenerateTopicSceneImageJob::references($last));
        $this->actingAs($this->user)->get(route('topic-shorts.show', $short))->assertInertia(fn (Assert $page) => $page
            ->where('short.characters.0.name', 'Goku')->whereNot('short.characters.0.image_url', null)->where('short.scenes.2.characters', ['Goku', 'Frieza']));

        // Ảnh wiki / tải lên của cảnh làm mẫu bố cục khi vẽ lại.
        Storage::disk(config('videoaff.disk'))->put('topic-shorts/test/scene.png', self::png());
        $last->update(['image_source' => 'upload', 'image_status' => 'ready', 'image_path' => 'topic-shorts/test/scene.png']);
        $this->assertSame([$short->characters[0]['image_path'], 'topic-shorts/test/scene.png'], GenerateTopicSceneImageJob::references($last->fresh()));
        $this->assertStringContainsString('LAST attached image', GenerateTopicSceneImageJob::prompt($last->fresh()));

        // Viết lại vẫn giữ ảnh mẫu của nhân vật trùng tên.
        $short->update(['status' => 'writing']);
        (new WriteTopicShortJob($short))->handle();
        $this->assertNotNull($short->fresh()->characters[0]['image_path']);

        // Vẽ lại tất cả: bỏ qua ảnh tự tải lên.
        $short->scenes()->update(['image_status' => 'ready']);
        $this->actingAs($this->user)->post(route('topic-shorts.muse-all', $short), ['image_style' => 'anime'])->assertSessionHas('status');
        $this->assertSame('anime', $short->fresh()->image_style);
        $this->assertStringContainsString('TV anime frame', GenerateTopicSceneImageJob::prompt($short->scenes()->first()));
        $this->assertSame($short->scenes()->count(), $short->scenes()->where('image_status', 'pending')->count());
        $this->actingAs(User::factory()->create())->post(route('topic-shorts.muse-all', $short))->assertForbidden();
    }

    public function test_subtitle_chunks_split_long_narration_in_order()
    {
        $chunks = ShortVideo::chunks('Goku nhìn Krillin ngã xuống, cơn giận dâng trào và mái tóc anh bỗng hoá vàng rực.', 1000, 5000);
        $this->assertGreaterThan(1, count($chunks));
        $this->assertSame(1000, $chunks[0][0]);
        $this->assertSame(5000, end($chunks)[1]);
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(7, count(explode(' ', $chunk[2])));
        }
    }
}
