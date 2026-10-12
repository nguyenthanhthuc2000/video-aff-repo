<?php

namespace Tests\Feature;

use App\Jobs\AnimateShotJob;
use App\Jobs\SynthesizeShotAudioJob;
use App\Models\Episode;
use App\Models\Series;
use App\Models\Shot;
use App\Models\User;
use App\Services\Film\EpisodeDialogue;
use App\Services\Film\ShotAudio;
use App\Services\Film\ShotClipPrompt;
use App\Services\Film\VoiceReference;
use App\Services\Film\Voices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Video từng shot: mỗi nhân vật một giọng riêng, cố định ở mọi shot; Muse làm clip khớp môi rồi ghép với lời thoại. */
class ShotVideoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Series $series;

    private Episode $episode;

    /** @var list<array> Các yêu cầu đọc lời gửi dịch vụ audio. */
    private array $spoken = [];

    /** @var list<array> Các yêu cầu gửi dịch vụ biểu cảm. */
    private array $expressive = [];

    private int $polls = 0;

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

        Http::fake(['expressive:3200/*' => function ($request) {
            // Dịch vụ biểu cảm giả: gửi → mã; hỏi lần đầu đang đọc, lần sau xong.
            if ($request->method() === 'POST') {
                $this->expressive[] = $request->data();

                return Http::response(['id' => 'task-'.count($this->expressive)], 202);
            }
            if (++$this->polls % 2 === 1) {
                return Http::response(['state' => 'pending', 'done' => 0, 'total' => 1]);
            }

            return Http::response(['state' => 'done', 'base64' => base64_encode(self::wav(3)), 'duration' => 3.0, 'lines' => [['start' => 0.3, 'end' => 2.5]]]);
        }, '*/tts' => function ($request) {
            $this->spoken[] = $request->data();
            $count = count($request->data()['lines']);

            return Http::response([
                'base64' => base64_encode(self::wav(1.0 + $count)),
                'duration' => 1.0 + $count,
                'lines' => array_map(fn ($i) => ['start' => 0.3 + $i, 'end' => 1.1 + $i], range(0, $count - 1)),
            ]);
        }]);
    }

    private function params(array $extra = []): array
    {
        return ['series' => $this->series->id, 'episode' => $this->episode->id, ...$extra];
    }

    /** WAV im lặng hợp lệ (16-bit mono 24 kHz). */
    private static function wav(float $seconds): string
    {
        $data = str_repeat("\0\0", (int) (24000 * $seconds));

        return 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16).'data'.pack('V', strlen($data)).$data;
    }

    public function test_each_character_gets_a_distinct_voice_kept_in_every_shot()
    {
        $characters = $this->series->characters()->get();
        $voices = $characters->pluck('voice.voice');
        $this->assertSame($voices->unique()->count(), $voices->count());
        $this->assertNotContains(Voices::NARRATOR['voice'], $voices);
        // Hết giọng trong nhóm thì vẫn khác (đổi cao độ).
        $this->assertSame('am_adam', Voices::forType('kid_boy', ['am_puck'])['voice']);
        $this->assertNotEquals(Voices::forType('elder', [])['pitch'], Voices::forType('elder', ['bm_fable', 'bm_daniel'])['pitch']);

        // Đọc lời mọi shot: mỗi câu dùng đúng giọng của nhân vật nói câu đó, như nhau ở mọi shot.
        $this->actingAs($this->user)->post(route('series.episodes.voices', $this->params()))->assertSessionHas('status');
        $milo = $characters->first();
        $voicesUsed = collect($this->episode->shots()->get())->flatMap(fn (Shot $shot) => collect(ShotAudio::lines($shot))->where('character_id', $milo->id)->pluck('voice'))->unique();
        $this->assertSame([$milo->voice['voice']], $voicesUsed->values()->all());
        $this->assertNotEmpty($this->spoken);

        $shot = $this->episode->shots()->get()->first(fn (Shot $s) => $s->audio_path !== null);
        $this->assertNotNull($shot);

        // Tải lời thoại: cả tập (.zip: từng shot, cả tập đúng nhịp shot, kịch bản) và từng shot (.wav).
        $response = $this->actingAs($this->user)->get(route('series.episodes.dialogue', $this->params()))->assertOk();
        $zip = new \ZipArchive;
        $zip->open($response->baseResponse->getFile()->getPathname());
        $this->assertNotFalse($zip->locateName('ca-tap.wav'));
        $this->assertNotFalse($zip->locateName('shots/shot-'.EpisodeDialogue::shotNumber($shot).'.wav'));
        $this->assertStringContainsString($this->episode->title, (string) $zip->getFromName('loi-thoai.txt'));
        $zip->close();
        $this->actingAs($this->user)->get(route('series.episodes.shots.audio', $this->params(['shot' => $shot->id])))->assertOk()->assertDownload();
        $this->actingAs(User::factory()->create())->get(route('series.episodes.dialogue', $this->params()))->assertForbidden();
        Storage::disk(config('videoaff.disk'))->assertExists($shot->audio_path);
        $this->assertTrue(ShotAudio::isCurrent($shot));
        $this->assertGreaterThanOrEqual($shot->audio_ms, $shot->duration_ms);

        // Đổi giọng nhân vật: tiếng các shot của nhân vật đó thành bản cũ, đọc lại thì dùng giọng mới.
        $speaker = $characters->firstWhere('id', collect(ShotAudio::lines($shot))->pluck('character_id')->filter()->first()) ?? $milo;
        $this->actingAs($this->user)->put(route('series.characters.update', ['series' => $this->series->id, 'character' => $speaker->id]), [
            'name' => $speaker->name, 'role' => $speaker->role, 'appearance' => $speaker->appearance, 'voice_type' => 'creature', 'voice' => 'am_santa',
        ])->assertSessionHasNoErrors();
        $this->assertSame('am_santa', $speaker->fresh()->voice['voice']);
        if (collect(ShotAudio::lines($shot))->contains('character_id', $speaker->id)) {
            $this->assertFalse(ShotAudio::isCurrent($shot->fresh()));
        }
        $this->actingAs($this->user)->get(route('series.episodes.show', $this->params()))
            ->assertInertia(fn (Assert $page) => $page->has('episode.shots.0.audio_url')->has('episode.shots.0.video_url')->where('pricing.clip', AnimateShotJob::price()));
    }

    public function test_page_shows_dialogue_being_read_until_done()
    {
        Queue::fake();
        $this->actingAs($this->user)->post(route('series.episodes.voices', $this->params()));
        $talking = $this->episode->shots()->get()->filter(fn (Shot $shot) => ShotAudio::key($shot) !== null);
        Queue::assertPushed(SynthesizeShotAudioJob::class, $talking->count());
        $this->assertTrue(ShotAudio::isPending($talking->first()));
        $this->actingAs($this->user)->get(route('series.episodes.show', $this->params()))
            ->assertInertia(fn (Assert $page) => $page->where('episode.shots.'.$talking->keys()->first().'.audio_pending', true));

        (new SynthesizeShotAudioJob($talking->first()))->handle();
        $this->assertFalse(ShotAudio::isPending($talking->first()));
        $this->assertNotNull($talking->first()->fresh()->audio_path);
    }

    public function test_kokoro_follows_emotion_and_expressive_engine_clones_character_voice()
    {
        $shot = $this->episode->shots()->get()->first(fn (Shot $s) => count(ShotAudio::lines($s)) > 0);
        $shot->update(['dialogue' => [['character_id' => $this->series->characters()->first()->id, 'line' => 'We did it!', 'emotion' => 'excited']]]);
        $character = $this->series->characters()->first();
        [$line] = ShotAudio::lines($shot->fresh());
        // Kokoro: hào hứng thì nhanh và cao hơn giọng gốc của nhân vật.
        $this->assertGreaterThan($character->voice['speed'], $line['speed']);
        $this->assertGreaterThan($character->voice['pitch'], $line['pitch']);
        ShotAudio::ensure($shot->fresh());
        $kokoroKey = $shot->fresh()->audio_key;
        $this->assertNotNull($kokoroKey);

        // Đổi sang đọc biểu cảm: tiếng Kokoro thành bản cũ; Chatterbox nhận giọng mẫu Kokoro của nhân vật + độ biểu cảm theo cảm xúc.
        Queue::fake();
        $this->actingAs($this->user)->post(route('series.episodes.voices', $this->params()), ['engine' => 'expressive'])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'biểu cảm'));
        $this->assertSame('expressive', $this->episode->fresh()->voice_engine);
        $shot = $shot->fresh();
        $this->assertFalse(ShotAudio::isCurrent($shot));

        $job = new SynthesizeShotAudioJob($shot);
        $job->handle();
        $this->assertCount(1, $this->expressive);
        $this->assertSame(0.85, $this->expressive[0]['lines'][0]['exaggeration']);
        $reference = VoiceReference::path($this->series->id, $character->voice);
        Storage::disk(config('videoaff.disk'))->assertExists($reference);
        $this->assertSame(base64_encode(Storage::disk(config('videoaff.disk'))->get($reference)), $this->expressive[0]['lines'][0]['ref']);
        $this->assertTrue(ShotAudio::isPending($shot));
        $job->handle(); // đang đọc
        $this->assertFalse(ShotAudio::isCurrent($shot->fresh()));
        $job->handle(); // xong
        $this->assertTrue(ShotAudio::isCurrent($shot->fresh()));
        $this->assertNotSame($kokoroKey, $shot->fresh()->audio_key);
        $this->assertFalse(ShotAudio::isPending($shot));
        $this->assertCount(1, $this->expressive);
        $this->actingAs($this->user)->get(route('series.episodes.show', $this->params()))
            ->assertInertia(fn (Assert $page) => $page->where('episode.voice_engine', 'expressive')->has('voiceEngines', 2));
    }

    public function test_create_video_needs_keyframes_then_makes_lip_synced_clip_with_character_voices()
    {
        $this->actingAs($this->user)->post(route('series.episodes.clips', $this->params()))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'khung hình'));

        $this->episode->shots()->update(['image_status' => 'ready', 'image_path' => 'k.png']);
        Storage::disk(config('videoaff.disk'))->put('k.png', 'x');
        Queue::fake();
        $this->actingAs($this->user)->post(route('series.episodes.clips', $this->params()))->assertSessionHas('status');
        Queue::assertPushed(AnimateShotJob::class, 6);
        $this->assertSame('animating', $this->episode->fresh()->status);
        $this->assertSame(6, $this->episode->shots()->where('clip_status', 'pending')->count());

        // Mỗi job: lần đầu đọc lời + gửi Muse (kèm tiếng thoại), lần sau lấy clip rồi ghép với lời thoại.
        foreach ($this->episode->shots()->get() as $shot) {
            $job = new AnimateShotJob($shot);
            $job->handle();
            $job->handle();
        }

        $shots = $this->episode->shots()->get();
        $this->assertSame(6, $shots->where('clip_status', 'ready')->count());
        $this->assertSame('animated', $this->episode->fresh()->status);
        $this->assertSame(6, $this->user->creditTransactions()->where('reference', 'like', 'shot-clip:%')->count());
        $talking = $shots->first(fn (Shot $s) => $s->audio_path !== null);
        Storage::disk(config('videoaff.disk'))->assertExists($talking->video_path);

        // Video ghép đúng 16:9, đủ thời lượng shot, có tiếng.
        $file = tempnam(sys_get_temp_dir(), 'shot').'.mp4';
        file_put_contents($file, Storage::disk(config('videoaff.disk'))->get($talking->video_path));
        $probe = Process::run(['ffprobe', '-v', 'error', '-show_entries', 'stream=codec_type,width,height:format=duration', '-of', 'json', $file])->output();
        $probe = json_decode($probe, true);
        @unlink($file);
        $this->assertSame([1920, 1080], [$probe['streams'][0]['width'], $probe['streams'][0]['height']]);
        $this->assertContains('audio', array_column($probe['streams'], 'codec_type'));
        $this->assertEqualsWithDelta($talking->duration_ms / 1000, (float) $probe['format']['duration'], 0.15);

        $prompt = ShotClipPrompt::build($talking);
        $this->assertStringContainsString('dialogue track', $prompt);
        $this->assertMatchesRegularExpression('/0\.3s–1\.1s: (.+ speaks this line|off-screen narrator)/u', $prompt);
    }

    public function test_remake_one_shot_video_and_voice_sample()
    {
        $shot = $this->episode->shots()->first();
        $this->actingAs($this->user)->post(route('series.episodes.shots.clip', $this->params(['shot' => $shot->id])))
            ->assertSessionHas('status', 'Shot cần có khung hình trước.');

        $shot->update(['image_status' => 'ready', 'image_path' => 'k.png']);
        Queue::fake();
        $this->actingAs($this->user)->post(route('series.episodes.shots.clip', $this->params(['shot' => $shot->id])), ['notes' => 'waves'])->assertSessionHas('status');
        Queue::assertPushed(AnimateShotJob::class, fn ($job) => $job->notes === 'waves' && $job->shot->is($shot));
        $this->assertSame('pending', $shot->fresh()->clip_status);
        // Đang làm video: không sửa shot, không đọc chồng.
        $this->actingAs($this->user)->put(route('series.episodes.shots.update', $this->params(['shot' => $shot->id])), ['camera' => 'close-up', 'action' => 'x', 'seconds' => 3])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'đang làm video'));
        (new SynthesizeShotAudioJob($shot))->handle();
        $this->assertNull($shot->fresh()->audio_path);

        $character = $this->series->characters()->first();
        $this->actingAs($this->user)->post(route('series.characters.voice-sample', ['series' => $this->series->id, 'character' => $character->id]))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'nghe thử'));
        $this->assertSame($character->voice['voice'], $this->spoken[0]['lines'][0]['voice']);
        $this->actingAs($this->user)->get(route('series.characters.show', ['series' => $this->series->id, 'character' => $character->id]))
            ->assertInertia(fn (Assert $page) => $page->whereNot('character.voice_sample_url', null)->has('voices'));

        $other = User::factory()->create();
        $this->actingAs($other)->post(route('series.episodes.clips', $this->params()))->assertForbidden();
    }
}
