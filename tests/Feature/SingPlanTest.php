<?php

namespace Tests\Feature;

use App\Enums\SceneClipStatus;
use App\Enums\SceneImageStatus;
use App\Enums\VideoProjectStatus;
use App\Enums\VoiceMode;
use App\Jobs\AnalyzeVocalsJob;
use App\Jobs\GenerateSceneClipJob;
use App\Models\AudioTrack;
use App\Models\Scene;
use App\Models\SceneImage;
use App\Models\User;
use App\Models\VideoProject;
use App\Services\Audio\MusicAnalyzer;
use App\Services\Audio\VocalAnalyzer;
use App\Services\Video\ClipGenerator;
use App\Services\Video\ReferenceClip;
use App\Support\LyricsAligner;
use App\Support\SingPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SingPlanTest extends TestCase
{
    use RefreshDatabase;

    /** Bài 60 giây: dạo nhạc 0–12s, câu 1 12–16s, câu 2 17–21s, nghỉ, câu 3 30–34s. */
    private const ANALYSIS = [
        'duration' => 60,
        'vocals' => [['start' => 12.0, 'end' => 16.0], ['start' => 17.0, 'end' => 21.0], ['start' => 30.0, 'end' => 34.0]],
        'words' => [
            ['start' => 12.1, 'end' => 12.6, 'text' => 'Em'], ['start' => 12.6, 'end' => 13.2, 'text' => 'thua'],
            ['start' => 13.2, 'end' => 14.0, 'text' => 'cô'], ['start' => 14.0, 'end' => 15.5, 'text' => 'ta'],
            ['start' => 17.2, 'end' => 18.0, 'text' => 'một'], ['start' => 18.0, 'end' => 19.0, 'text' => 'tương'], ['start' => 19.0, 'end' => 20.5, 'text' => 'lai'],
        ],
    ];

    public function test_start_is_at_a_sung_phrase_not_the_intro()
    {
        $levels = array_fill(0, 60, -20.0);

        $this->assertSame(11.6, SingPlan::bestStart(self::ANALYSIS, $levels, 10));
        $this->assertSame(0.0, SingPlan::bestStart(self::ANALYSIS, $levels, 90));
    }

    public function test_cuts_move_into_the_pause_between_phrases()
    {
        // Cảnh 5s + 5s từ giây 11.6: chỗ cắt 16.6 nằm đúng khoảng nghỉ 16–17.
        $this->assertSame([5000, 5000], SingPlan::retime([5000, 5000], self::ANALYSIS, 11.6));
        // Chỗ cắt 15.6 (giữa câu 1) dời sang khoảng nghỉ 16.15.
        $this->assertSame([4550, 5450], SingPlan::retime([4000, 6000], self::ANALYSIS, 11.6));
    }

    public function test_timeline_lip_syncs_only_while_singing()
    {
        $timeline = SingPlan::timeline(self::ANALYSIS, 10.0, 8.0);

        $this->assertStringContainsString('0.0–2.0s: no singing — lips closed, she smiles shyly', $timeline);
        $this->assertStringContainsString('2.0–6.0s: singing "Em thua cô ta"', $timeline);
        $this->assertStringContainsString('7.0–8.0s: singing "một"', $timeline);
        $this->assertStringContainsString('Move the lips ONLY during the singing parts', $timeline);
    }

    public function test_sing_clip_without_muse_plan_uses_existing_vocal_analysis()
    {
        Storage::fake('public');
        Queue::fake();
        config(['videoaff.image_driver' => 'browser', 'videoaff.muse_browser.url' => 'http://browser:3000']);
        Http::fake(['http://browser:3000/tasks' => Http::response(['state' => 'pending'])]);

        $project = VideoProject::factory()->create(['status' => VideoProjectStatus::Reviewing, 'voice_mode' => VoiceMode::Sing]);
        $track = AudioTrack::factory()->for($project->user)->create(['path' => 'audio/song.mp3']);
        Storage::disk('public')->put($track->path, file_get_contents(base_path('tests/Fixtures/tiny.mp3')));
        $project->update(['audio_track_id' => $track->id]);
        $scene = Scene::factory()->for($project)->create(['position' => 1, 'duration_ms' => 500, 'voiceover' => '', 'clip_status' => SceneClipStatus::Pending]);
        $image = SceneImage::factory()->for($scene)->create(['status' => SceneImageStatus::Approved, 'path' => 'scenes/1.jpg']);
        Storage::disk('public')->put($image->path, 'scene-bytes');

        // Gửi bài cho dịch vụ phân tích, hỏi lại: đang chạy, rồi xong (câu hát 0.1–0.4s).
        Http::fake([
            'http://audio:3100/analyze' => Http::response(['id' => 'task1'], 202),
            'http://audio:3100/analyze/task1' => Http::sequence()
                ->push(['state' => 'pending'])
                ->push(['state' => 'done', 'duration' => 0.5, 'vocals' => [['start' => 0.1, 'end' => 0.4]], 'words' => [['start' => 0.1, 'end' => 0.4, 'text' => 'la']]]),
        ]);
        foreach (range(1, 3) as $attempt) {
            $analysis = (new AnalyzeVocalsJob($track->fresh()))->withFakeQueueInteractions();
            $analysis->handle(app(VocalAnalyzer::class));
            $attempt < 3 ? $analysis->assertReleased(AnalyzeVocalsJob::POLL_SECONDS) : $analysis->assertNotReleased();
        }
        $this->assertSame('ready', $track->fresh()->vocal_status);

        $job = (new GenerateSceneClipJob($scene->fresh()))->withFakeQueueInteractions();
        $job->handle(app(ClipGenerator::class), app(ReferenceClip::class), app(MusicAnalyzer::class));
        $job->assertReleased(30);
        $this->assertStringContainsString('singing "la"', (string) $scene->fresh()->clip_prompt);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/tasks') && count($request['images']) === 2);
    }

    public function test_failed_analysis_is_recorded()
    {
        Http::fake([
            'http://audio:3100/analyze' => Http::response(['id' => 'task2'], 202),
            'http://audio:3100/analyze/task2' => Http::response(['state' => 'failed', 'error' => 'hết bộ nhớ']),
        ]);
        Storage::fake('public');
        $track = AudioTrack::factory()->create(['path' => 'audio/a.mp3', 'vocal_status' => 'pending']);
        Storage::disk('public')->put($track->path, 'x');

        (new AnalyzeVocalsJob($track))->withFakeQueueInteractions()->handle(app(VocalAnalyzer::class));
        $job = (new AnalyzeVocalsJob($track))->withFakeQueueInteractions();
        $job->handle(app(VocalAnalyzer::class));
        $job->assertFailed();
        $job->failed(new \RuntimeException('Phân tích giọng hát lỗi: hết bộ nhớ'));

        $this->assertSame('failed', $track->fresh()->vocal_status);
        $this->assertStringContainsString('hết bộ nhớ', $track->fresh()->vocal_error);
    }

    public function test_lyrics_replace_misheard_words_and_keep_timing()
    {
        $heard = [
            ['start' => 1.0, 'end' => 1.4, 'text' => 'Bao'], ['start' => 1.4, 'end' => 1.8, 'text' => 'lâu'],
            ['start' => 1.8, 'end' => 2.2, 'text' => 'dự'], ['start' => 2.2, 'end' => 2.6, 'text' => 'vài'],
            ['start' => 3.0, 'end' => 3.4, 'text' => 'phải'], ['start' => 3.4, 'end' => 3.8, 'text' => 'thua'],
        ];

        $aligned = LyricsAligner::align($heard, "Bao lâu dựa vai nay,\nphải thua");

        $this->assertSame(['Bao', 'lâu', 'dựa', 'vai', 'nay', 'phải', 'thua'], array_column($aligned, 'text'));
        $this->assertSame(1.8, $aligned[2]['start']);
        // "nay" không nghe được: chia đều khoảng trống giữa "vai" và "phải".
        $this->assertEqualsWithDelta(2.6, $aligned[4]['start'], 0.01);
        $this->assertSame(3.4, $aligned[6]['start']);
    }

    public function test_save_lyrics()
    {
        $track = AudioTrack::factory()->create();

        $this->actingAs($track->user)->put(route('audio-tracks.lyrics', $track), ['lyrics' => '  Em thua cô ta  '])->assertSessionHasNoErrors();
        $this->assertSame('Em thua cô ta', $track->fresh()->lyrics);
        $this->actingAs(User::factory()->create())->put(route('audio-tracks.lyrics', $track), ['lyrics' => 'x'])->assertForbidden();
    }
}
