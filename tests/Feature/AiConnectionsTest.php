<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeVocalsJob;
use App\Jobs\GenerateSongJob;
use App\Models\AppSetting;
use App\Models\User;
use App\Services\Film\FakeFilmClipGenerator;
use App\Services\Image\ImageGeneratorException;
use App\Services\MiniMax\MiniMaxClipGenerator;
use App\Services\MiniMax\MiniMaxImageGenerator;
use App\Support\AiKeys;
use App\Support\AiProviders;
use App\Support\RealArtists;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Kết nối AI: khoá MiniMax / fal / Claude lưu mã hoá, kiểm tra kết nối, công cụ từng bước; MiniMax ảnh / clip / nhạc (giả lập HTTP). */
class AiConnectionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('videoaff.disk'));
        $this->admin = User::factory()->admin()->create();
        config(['videoaff.minimax.key' => null, 'videoaff.fal.key' => null]);
    }

    public function test_keys_are_stored_encrypted_masked_and_checked_for_free()
    {
        $this->actingAs(User::factory()->create())->get(route('admin.ai-connections.show'))->assertForbidden();

        $this->actingAs($this->admin)->put(route('admin.ai-connections.keys'), [
            'keys' => ['minimax' => 'mm-secret-key-1234', 'fal' => ''], 'minimax_region' => 'china',
        ])->assertSessionHasNoErrors();

        // Lưu mã hoá: trong database không có khoá dạng chữ thường.
        $this->assertStringNotContainsString('mm-secret-key-1234', (string) DB::table('app_settings')->where('key', 'minimax_api_key')->value('value'));
        $this->assertSame('mm-secret-key-1234', config('videoaff.minimax.key'));
        $this->assertSame('china', config('videoaff.minimax.region'));

        Http::fake(['https://api.minimaxi.com/v1/models' => Http::sequence()
            ->push(['object' => 'list', 'data' => []])
            ->push(['base_resp' => ['status_code' => 2049, 'status_msg' => 'invalid api key']], 401)]);
        $this->actingAs($this->admin)->post(route('admin.ai-connections.check', 'minimax'))->assertSessionHas('status', fn ($s) => str_contains($s, 'Kết nối được'));
        Http::assertSent(fn ($r) => $r->url() === 'https://api.minimaxi.com/v1/models' && $r->hasHeader('Authorization', 'Bearer mm-secret-key-1234'));
        $this->assertTrue(AiKeys::valid('minimax'));

        $this->actingAs($this->admin)->get(route('admin.ai-connections.show'))->assertInertia(fn (Assert $page) => $page
            ->component('admin/ai-connections')
            ->where('keys.minimax.masked', '••••1234')
            ->where('keys.minimax.source', 'ui')
            ->where('keys.minimax.status.ok', true)
            ->where('keys.fal.masked', null)
            ->where('steps.clip.selected', 'minimax'));
        // Trang không bao giờ có khoá đầy đủ.
        $this->assertStringNotContainsString('mm-secret-key-1234', $this->actingAs($this->admin)->get(route('admin.ai-connections.show'))->getContent());

        // Khoá sai (lần kiểm tra sau).
        $this->assertFalse(AiKeys::check('minimax')['ok']);
        $this->assertFalse(AiKeys::valid('minimax'));

        // Xoá khoá đã nhập.
        $this->actingAs($this->admin)->put(route('admin.ai-connections.keys'), ['clear' => ['minimax'], 'minimax_region' => 'global']);
        $this->assertNull(AppSetting::get('minimax_api_key'));
    }

    public function test_providers_default_to_minimax_when_its_key_works_and_can_be_changed()
    {
        // Không khoá, chưa nối Muse (test): giả lập.
        $this->assertSame('fake', AiProviders::selected('clip'));
        $this->assertInstanceOf(FakeFilmClipGenerator::class, app('film.clip'));
        $this->assertSame('manual', AiProviders::selected('music'));

        config(['videoaff.minimax.key' => 'k', 'videoaff.fal.key' => 'f']);
        AppSetting::put(AiKeys::STATUS, json_encode(['minimax' => ['ok' => true, 'message' => '', 'checked_at' => now()->toIso8601String()]]));
        $this->assertSame('minimax', AiProviders::selected('keyframe'));
        $this->assertInstanceOf(MiniMaxClipGenerator::class, app('film.clip'));
        $this->assertInstanceOf(MiniMaxImageGenerator::class, app('film.character_image'));
        $this->assertSame('minimax', AiProviders::selected('music'));
        $this->assertStringContainsString('KHÔNG hát nhép', AiProviders::options('clip')['minimax']['note']);

        $this->actingAs($this->admin)->put(route('admin.ai-connections.providers'), ['providers' => ['clip' => 'fake', 'music' => 'manual']])->assertSessionHasNoErrors();
        $this->assertSame('fake', AiProviders::selected('clip'));
        $this->assertSame('manual', AiProviders::selected('music'));
        $this->assertSame('minimax', AiProviders::selected('keyframe'));
    }

    public function test_minimax_image_uses_one_character_reference_and_frame_shape()
    {
        config(['videoaff.minimax.key' => 'k']);
        Storage::disk(config('videoaff.disk'))->put('refs/singer.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
        Http::fake(['https://api.minimax.io/v1/image_generation' => Http::response(['data' => ['image_base64' => [base64_encode('JPEGDATA')]], 'base_resp' => ['status_code' => 0]])]);

        $generator = new MiniMaxImageGenerator;
        $code = $generator->start(['refs/singer.png', 'refs/other.png'], "Frame: TALL VERTICAL 9:16 portrait frame.\nThe singer on stage.");
        $image = $generator->poll($code, []);

        $this->assertSame('JPEGDATA', $image->contents);
        $this->assertSame(0.0035, $image->costUsd);
        Http::assertSent(fn ($r) => $r['model'] === 'image-01' && $r['aspect_ratio'] === '9:16' && $r['response_format'] === 'base64'
            && count($r['subject_reference']) === 1 && $r['subject_reference'][0]['type'] === 'character'
            && str_starts_with($r['subject_reference'][0]['image_file'], 'data:image/png;base64,'));
    }

    public function test_hailuo_clip_is_created_polled_and_downloaded()
    {
        config(['videoaff.minimax.key' => 'k']);
        Storage::disk(config('videoaff.disk'))->put('frames/f.png', 'PNGDATA');
        Http::fake([
            'https://api.minimax.io/v1/video_generation' => Http::response(['task_id' => 't1', 'base_resp' => ['status_code' => 0]]),
            'https://api.minimax.io/v1/query/video_generation*' => Http::sequence()
                ->push(['task_id' => 't1', 'status' => 'Processing', 'base_resp' => ['status_code' => 0]])
                ->push(['task_id' => 't1', 'status' => 'Success', 'file_id' => '99', 'base_resp' => ['status_code' => 0]]),
            'https://api.minimax.io/v1/files/retrieve*' => Http::response(['file' => ['file_id' => 99, 'download_url' => 'https://cdn.minimax.io/v.mp4'], 'base_resp' => ['status_code' => 0]]),
            'https://cdn.minimax.io/v.mp4' => Http::response('MP4DATA'),
        ]);

        $generator = new MiniMaxClipGenerator;
        $this->assertSame('t1', $generator->start('frames/f.png', 'Make a 8-second music video clip from the attached first frame.', null, 'tmp/song.m4a'));
        $this->assertNull($generator->poll('t1'));
        $this->assertSame(['contents' => 'MP4DATA', 'extension' => 'mp4'], $generator->poll('t1'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/video_generation') && $r['model'] === 'MiniMax-Hailuo-02'
            && $r['duration'] === 10 && $r['resolution'] === '768P' && str_starts_with($r['first_frame_image'], 'data:'));
        $this->assertSame(6, MiniMaxClipGenerator::seconds('Make a 5-second clip'));
        $this->assertSame(0.28, MiniMaxClipGenerator::price(6));
    }

    public function test_minimax_errors_are_explained()
    {
        config(['videoaff.minimax.key' => 'k']);
        Storage::disk(config('videoaff.disk'))->put('frames/f.png', 'PNGDATA');
        Http::fake(['*' => Http::response(['base_resp' => ['status_code' => 1008, 'status_msg' => 'insufficient balance']])]);
        try {
            (new MiniMaxClipGenerator)->start('frames/f.png', 'x');
            $this->fail('Phải báo lỗi.');
        } catch (ImageGeneratorException $e) {
            $this->assertSame('Tài khoản MiniMax hết tiền.', $e->getMessage());
            $this->assertFalse($e->retryable);
        }
    }

    public function test_minimax_direct_music_for_a_new_song()
    {
        Queue::fake([AnalyzeVocalsJob::class]);
        config(['videoaff.minimax.key' => 'k']);
        $user = User::factory()->create();
        $draft = $user->songDrafts()->create(['source_url' => 'https://youtu.be/x', 'status' => 'ready', 'profile' => ['structure' => []],
            'song' => ['title' => 'Nắng', 'language' => 'vi', 'concept' => 'c', 'genre' => 'pop', 'tempo_bpm' => 90, 'vocal' => 'f', 'style_prompt' => 'pop, 90 BPM',
                'lyrics' => "[Verse]\nA\n[Chorus]\nB", 'mv_idea' => 'i', 'singer' => 's']]);
        Http::fake([
            'https://api.minimax.io/v1/music_generation' => Http::response(['data' => ['status' => 2, 'audio' => 'https://cdn.minimax.io/song.mp3'], 'base_resp' => ['status_code' => 0]]),
            'https://cdn.minimax.io/song.mp3' => Http::response('ID3song'),
        ]);

        $this->actingAs($user)->post(route('song-drafts.generate', $draft), ['provider' => 'minimax-direct', 'confirm_cost' => 1])->assertSessionHasNoErrors();
        (new GenerateSongJob($draft))->handle();
        (new GenerateSongJob($draft))->handle();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/music_generation') && $r['model'] === 'music-3.0' && $r['lyrics'] === "[Verse]\nA\n[Chorus]\nB"
            && $r['output_format'] === 'url');
        $this->assertSame('ID3song', Storage::disk(config('videoaff.disk'))->get($draft->fresh()->audioTrack->path));
    }

    public function test_example_bands_are_added_once()
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('shorts.bands.examples'))->assertSessionHas('status', 'Đã thêm 3 ban nhạc mẫu.');
        $this->actingAs($user)->post(route('shorts.bands.examples'));
        $bands = $user->virtualBands()->orderBy('id')->get();
        $this->assertSame(['Velvet Thunder', 'Iron Lantern', 'Aurora Requiem'], $bands->pluck('name')->all());
        $this->assertTrue($bands->every(fn ($b) => collect($b->members)->every(fn ($m) => filled($m['look']) && filled($m['look_old']))));
        $this->assertNull(RealArtists::find(json_encode($bands->toArray())));
    }
}
