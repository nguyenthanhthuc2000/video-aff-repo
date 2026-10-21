<?php

namespace Tests\Feature;

use App\Jobs\FindTopicSceneImageJob;
use App\Jobs\GenerateTopicSceneImageJob;
use App\Jobs\RenderTopicShortJob;
use App\Jobs\WriteTopicShortJob;
use App\Models\TopicShort;
use App\Models\TopicShortScene;
use App\Models\User;
use App\Services\TopicShort\RemoteImage;
use App\Services\TopicShort\ShortVideo;
use App\Services\TopicShort\WikiImageSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
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
            'topic' => 'Lần đầu Goku biến thành Super Saiyan', 'language' => 'vi', 'target_seconds' => 45, 'wiki' => 'dragonball',
        ])->assertRedirect();
        $short = $this->user->topicShorts()->sole();
        $this->assertSame('writing', $short->status);
        Queue::assertPushed(WriteTopicShortJob::class);

        (new WriteTopicShortJob($short))->handle();
        $short->refresh();
        $this->assertSame('ready', $short->status, (string) $short->error);
        $this->assertNotEmpty($short->title);
        $scenes = $short->scenes()->get();
        $this->assertGreaterThanOrEqual(2, $scenes->count());
        $this->assertSame('searching', $scenes[0]->image_status);
        Queue::assertPushed(FindTopicSceneImageJob::class, $scenes->count());

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

        $this->actingAs($this->user)->post(route('topic-short-scenes.upload', $scene), ['image' => UploadedFile::fake()->image('mine.png', 720, 1280)])->assertSessionHasNoErrors();
        $scene->refresh();
        $this->assertSame('upload', $scene->image_source);
        Storage::disk(config('videoaff.disk'))->assertExists($scene->image_path);
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
