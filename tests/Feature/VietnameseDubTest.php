<?php

namespace Tests\Feature;

use App\Jobs\RenderEpisodeJob;
use App\Models\Episode;
use App\Models\Series;
use App\Models\Shot;
use App\Models\User;
use App\Services\Film\ShotDub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Xuất bản tiếng Việt, phụ đề tiếng Anh: Claude dịch lời, lồng tiếng Việt từng shot (Piper, tuỳ chọn đổi giọng nhân vật), ghép riêng. */
class VietnameseDubTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Series $series;

    private Episode $episode;

    /** @var list<array> */
    private array $spoken = [];

    /** @var list<array> */
    private array $converted = [];

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

        $disk = Storage::disk(config('videoaff.disk'));
        $file = tempnam(sys_get_temp_dir(), 'k').'.png';
        Process::run(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=orange:s=640x360', '-frames:v', '1', $file])->throw();
        $disk->put('k.png', (string) file_get_contents($file));
        @unlink($file);
        $this->episode->shots()->update(['image_status' => 'ready', 'image_path' => 'k.png', 'duration_ms' => 2000]);

        Http::fake(['expressive:3200/*' => function ($request) {
            if ($request->method() === 'POST') {
                $this->converted[] = $request->data();

                return Http::response(['id' => 'vc-'.count($this->converted)], 202);
            }

            return ++$this->polls % 2 === 1
                ? Http::response(['state' => 'pending', 'done' => 0, 'total' => 1])
                : Http::response(['state' => 'done', 'base64' => base64_encode(self::wav(2)), 'duration' => 2.0, 'lines' => []]);
        }, '*/tts' => function ($request) {
            $this->spoken[] = $request->data();
            $count = count($request->data()['lines']);

            return Http::response([
                'base64' => base64_encode(self::wav(0.8 * $count)),
                'duration' => 0.8 * $count,
                'lines' => array_map(fn ($i) => ['start' => 0.8 * $i, 'end' => 0.8 * ($i + 1)], range(0, $count - 1)),
            ]);
        }]);
    }

    private function params(array $extra = []): array
    {
        return ['series' => $this->series->id, 'episode' => $this->episode->id, ...$extra];
    }

    private static function wav(float $seconds): string
    {
        $data = str_repeat("\0\0", (int) (24000 * $seconds));

        return 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16).'data'.pack('V', strlen($data)).$data;
    }

    public function test_one_button_translates_dubs_and_renders_with_english_subtitles()
    {
        Queue::fake();
        $this->actingAs($this->user)->post(route('series.episodes.render', $this->params()), ['lang' => 'vi', 'sound_effects' => false])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'dịch lời'));
        $episode = $this->episode->fresh();
        $this->assertSame('rendering', $episode->vi_render_status);
        $this->assertNull($episode->render_status);
        $this->assertTrue($episode->vi_render_options['burn_subtitles']);
        Queue::assertPushed(RenderEpisodeJob::class, fn ($job) => $job->lang === 'vi');

        (new RenderEpisodeJob($episode, $episode->vi_render_options['run'], 'vi'))->handle();
        $episode->refresh();
        $this->assertSame('done', $episode->vi_render_status, (string) $episode->vi_render_error);
        $this->assertNotEmpty($episode->dub_vi['title']);
        $disk = Storage::disk(config('videoaff.disk'));
        $disk->assertExists($episode->vi_render_path);
        $this->assertStringContainsString('-vi-', $episode->vi_render_path);
        $this->assertNull($episode->render_path);

        // Lời tiếng Việt đọc bằng Piper; phụ đề vẫn là lời tiếng Anh, theo mốc câu tiếng Việt.
        $this->assertSame('vi', $this->spoken[0]['lines'][0]['lang']);
        $this->assertStringContainsString('Câu thoại', $this->spoken[0]['lines'][0]['text']);
        $talking = $episode->shots()->get()->first(fn (Shot $shot) => ! empty($shot->dialogue));
        $this->assertNotNull($talking->dub_vi_path);
        $this->assertNotEmpty($talking->dub_vi_lines);
        $this->assertStringContainsString(trim($talking->dialogue[0]['line']), $disk->get($episode->vi_srt_path));
        $this->assertSame(1, $this->user->creditTransactions()->where('description', 'like', '%bản tiếng Việt%')->count());
        $this->assertSame([], $this->converted);

        $this->actingAs($this->user)->get(route('series.episodes.show', $this->params()))
            ->assertInertia(fn (Assert $page) => $page->where('episode.render_vi.status', 'done')->where('episode.render_vi.needs_translation', false)
                ->whereNot('episode.render_vi.url', null)->has('dubVoices', 2));
        $this->actingAs($this->user)->get(route('series.episodes.render.download', $this->params(['lang' => 'vi'])))->assertDownload();
        $this->actingAs($this->user)->get(route('series.episodes.subtitles', $this->params(['lang' => 'vi'])))->assertDownload();
        $this->actingAs($this->user)->get(route('series.episodes.render.download', $this->params()))->assertRedirect();

        // Xuất lại với giọng nhân vật: không dịch lại (lời tiếng Anh không đổi), đổi giọng từng shot qua dịch vụ expressive.
        $spoken = count($this->spoken);
        $this->actingAs($this->user)->post(route('series.episodes.render', $this->params()), ['lang' => 'vi', 'vi_voice' => 'character', 'sound_effects' => false])
            ->assertSessionHas('status', fn ($s) => ! str_contains($s, 'dịch lời'));
        $episode->refresh();
        for ($i = 0; $i < 10 && $episode->vi_render_status === 'rendering'; $i++) {
            (new RenderEpisodeJob($episode, $episode->vi_render_options['run'], 'vi'))->handle();
            $episode->refresh();
        }
        $this->assertSame('done', $episode->vi_render_status, (string) $episode->vi_render_error);
        $this->assertGreaterThan($spoken, count($this->spoken));
        $this->assertNotEmpty($this->converted);
        $this->assertNotEmpty($this->converted[0]['segments'][0]['ref']);
    }

    public function test_place_speeds_up_long_lines_and_keeps_order()
    {
        $placed = ShotDub::place([['start_ms' => 500, 'end_ms' => 1500], ['start_ms' => 2000, 'end_ms' => 2800]], [2000, 600], 3000);
        // Câu 1 dài 2 s nhưng chỉ còn 1,42 s tới câu 2: đọc nhanh lên, không lấn câu 2.
        $this->assertSame(500, $placed[0]['start_ms']);
        $this->assertGreaterThan(1.3, $placed[0]['tempo']);
        $this->assertLessThanOrEqual(1920, $placed[0]['end_ms']);
        $this->assertSame(2000, $placed[1]['start_ms']);
        $this->assertSame(1.0, $placed[1]['tempo']);
        // Quá dài so với giới hạn tốc độ: lùi sớm hơn, không vượt cuối shot.
        $late = ShotDub::place([['start_ms' => 2500, 'end_ms' => 2900]], [1500], 3000);
        $this->assertLessThan(2500, $late[0]['start_ms']);
        $this->assertLessThanOrEqual(3000, $late[0]['end_ms']);
    }
}
