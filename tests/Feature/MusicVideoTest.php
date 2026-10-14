<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeVocalsJob;
use App\Jobs\ComposeSongDraftJob;
use App\Jobs\GenerateSongJob;
use App\Jobs\PlanSeriesJob;
use App\Models\AudioTrack;
use App\Models\Series;
use App\Models\SongDraft;
use App\Models\User;
use App\Services\Audio\YoutubeReference;
use App\Services\Film\EpisodeRenderer;
use App\Services\Film\FilmMuseClipGenerator;
use App\Services\Film\FilmMuseImageGenerator;
use App\Services\Film\LyricsOverlap;
use App\Services\Film\MusicVideoIdeas;
use App\Services\Film\MusicVideoStage;
use App\Services\Film\MusicVideoWriter;
use App\Services\Film\SeriesPlanner;
use App\Services\Film\ShotClipPrompt;
use App\Services\Film\ShotPrompt;
use App\Services\Film\SongAnalysis;
use App\Services\Film\SongWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** MV ca nhạc: trang tạo MV, kế hoạch theo bài hát, phân cảnh cắt đúng câu hát, hát nhép, ghép với bài hát gốc. */
class MusicVideoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('videoaff.disk'));
        $this->user = User::factory()->create();
        // Phân tích lời cần dịch vụ audio: không chạy trong test.
        Queue::fake([AnalyzeVocalsJob::class]);
    }

    /** Lời có mốc thời gian như dịch vụ audio trả về (giây tính từ đầu bài). */
    private function words(): array
    {
        $words = [];
        foreach ([[3.0, 'Walking alone tonight'], [7.2, 'the rain falls slow'], [12.5, 'Light me up'], [16.8, 'light me up again']] as [$start, $line]) {
            foreach (explode(' ', $line) as $i => $word) {
                $words[] = ['start' => $start + $i * 0.4, 'end' => $start + $i * 0.4 + 0.35, 'text' => $word];
            }
        }

        return $words;
    }

    private function track(array $override = []): AudioTrack
    {
        return $this->user->audioTracks()->create([
            'name' => 'Neon Rain', 'path' => 'audio/neon-rain.mp3', 'mime' => 'audio/mpeg', 'size_bytes' => 1000, 'duration_ms' => 200000,
            'rights_confirmed_at' => now(), 'vocal_status' => 'ready', 'vocal_analysis' => ['duration' => 200, 'vocals' => [], 'words' => $this->words()],
            ...$override,
        ]);
    }

    private function createMusicVideo(array $override = []): Series
    {
        $this->actingAs($this->user)->post(route('music-videos.store'), [
            'audio_track_id' => $this->track()->id,
            'rights' => 1,
            'song_end' => 30,
            'lyrics' => "[Verse]\nWalking alone tonight, the rain falls slow\n[Chorus]\nLight me up, light me up again",
            'idea' => 'Cô gái đi một mình trong thành phố mưa đêm, điệp khúc đèn neon bừng sáng.',
            'singer_name' => 'Lan',
            'singer' => 'Cô gái 24 tuổi, tóc ngang vai, áo khoác da đen.',
            'style_preset' => 'cinematic',
            'aspect_ratio' => '16:9',
            'lip_sync' => 'chorus',
            'driver' => 'fake',
            ...$override,
        ])->assertRedirect()->assertSessionHasNoErrors();

        return $this->user->series()->latest('id')->first();
    }

    public function test_create_page_lists_songs_options_and_available_drivers()
    {
        $this->track();

        $this->actingAs($this->user)->get(route('music-videos.create'))->assertInertia(fn (Assert $page) => $page
            ->component('music-videos/create')
            ->where('songs.0.name', 'Neon Rain')
            ->where('songs.0.vocal_status', 'ready')
            // Lời theo thời gian của cả bài để nghe thử: câu đang hát sáng lên.
            ->where('songs.0.lines.0.text', 'Walking alone tonight')
            ->where('songs.0.lines.0.end', 4.15)
            ->where('songs.0.lines.2.text', 'Light me up')
            ->has('songs.0.url')
            ->where('lipSyncModes.chorus', 'Chỉ điệp khúc')
            ->where('drivers.browser.available', false)
            ->where('drivers.fal.available', false)
            ->where('drivers.fake.available', true)
            ->where('defaultDriver', 'fake'));
    }

    public function test_uploading_a_song_creates_the_track_and_a_planned_music_video()
    {
        Process::fake(['*' => Process::result('{"streams":[],"format":{"duration":"95.5"}}')]);

        $series = $this->createMusicVideo([
            'audio_track_id' => null,
            'song' => UploadedFile::fake()->create('Mua Dem.mp3', 500, 'audio/mpeg'),
            'song_end' => null,
            'lip_sync' => 'full',
            'aspect_ratio' => '9:16',
            'style_preset' => '2d',
        ]);

        $track = $series->song;
        $this->assertSame('Mua Dem', $track->name);
        $this->assertSame(95500, $track->duration_ms);
        $this->assertNotNull($track->rights_confirmed_at);
        Queue::assertPushed(AnalyzeVocalsJob::class);

        $this->assertTrue($series->musicVideo());
        $this->assertSame([0, 95500], $series->songRange());
        $this->assertSame(96, $series->episode_seconds);
        $this->assertSame('9:16', $series->aspect_ratio);
        $this->assertSame(Series::MV_STYLE_PRESETS['2d']['style'], $series->style);
        $this->assertSame(['lip_sync' => 'full', 'driver' => 'fake', 'style_preset' => '2d', 'singer_name' => 'Lan', 'singer' => 'Cô gái 24 tuổi, tóc ngang vai, áo khoác da đen.'], $series->mv_options);
        $this->assertFalse($series->made_for_kids);
        // Kế hoạch (Claude mẫu): ca sĩ, bối cảnh, đúng một tập là MV.
        $this->assertSame('bible_ready', $series->status->value, (string) $series->error);
        $this->assertSame(1, $series->episodes()->count());
        $this->assertStringContainsString('Official Music Video', $series->episodes()->first()->title);
        $this->assertSame('main', $series->characters()->first()->role);
    }

    public function test_store_validates_song_rights_and_driver()
    {
        $this->actingAs($this->user)->post(route('music-videos.store'), [
            'idea' => 'Một MV về mưa đêm và ánh đèn neon trong thành phố.',
            'style_preset' => 'cinematic', 'aspect_ratio' => '16:9', 'lip_sync' => 'chorus', 'driver' => 'browser',
        ])->assertSessionHasErrors(['song', 'audio_track_id', 'rights', 'driver']);

        $this->actingAs($this->user)->post(route('music-videos.store'), [
            'audio_track_id' => $this->track()->id, 'rights' => 1, 'song_start' => 20, 'song_end' => 25,
            'idea' => 'Một MV về mưa đêm và ánh đèn neon trong thành phố.',
            'style_preset' => 'cinematic', 'aspect_ratio' => '16:9', 'lip_sync' => 'chorus', 'driver' => 'fake',
        ])->assertSessionHasErrors('song_end');
        $this->assertSame(0, Series::count());

        // Bài hát của người khác.
        $other = User::factory()->create()->audioTracks()->create(['name' => 'X', 'path' => 'audio/x.mp3', 'duration_ms' => 60000]);
        $this->actingAs($this->user)->post(route('music-videos.store'), ['audio_track_id' => $other->id])->assertSessionHasErrors('audio_track_id');
    }

    public function test_plan_prompt_has_lyrics_singer_and_lip_sync_mode()
    {
        Queue::fake([AnalyzeVocalsJob::class, PlanSeriesJob::class]);
        $series = $this->createMusicVideo();

        $text = SeriesPlanner::userText($series);
        $this->assertStringContainsString('Light me up, light me up again', $text);
        $this->assertStringContainsString('excerpt of 30 seconds', $text);
        $this->assertStringContainsString('The singer (main character) is named Lan', $text);
        $this->assertStringContainsString('lip-syncs only the chorus', $text);
    }

    public function test_claude_suggests_ideas_from_lyrics_and_style()
    {
        $token = $this->actingAs($this->user)->postJson(route('music-videos.ideas'), [
            'song_name' => 'Mua Dem', 'lyrics' => 'Light me up, light me up again', 'style_preset' => '2d', 'aspect_ratio' => '9:16',
            'previous' => ['Ý tưởng cũ'],
        ])->assertOk()->json('token');

        // Hàng đợi chạy ngay trong test: đã có 3 ý tưởng (Claude mẫu).
        $this->actingAs($this->user)->getJson(route('music-videos.ideas.show', $token))
            ->assertOk()->assertJsonPath('status', 'done')->assertJsonCount(3, 'ideas')
            ->assertJsonStructure(['ideas' => [['title', 'message', 'story', 'mood', 'singer', 'locations']]]);
        // Người khác không xem được.
        $this->actingAs(User::factory()->create())->getJson(route('music-videos.ideas.show', $token))->assertNotFound();

        $text = MusicVideoIdeas::text(['song_name' => 'Mua Dem', 'lyrics' => 'Light me up', 'style' => Series::MV_STYLE_PRESETS['2d']['style'], 'aspect_ratio' => '9:16', 'previous' => ['Ý tưởng cũ']]);
        $this->assertStringContainsString('Song title: Mua Dem', $text);
        $this->assertStringContainsString('vertical 9:16', $text);
        $this->assertStringContainsString('2D anime', $text);
        $this->assertStringContainsString("Already suggested (pitch different concepts):\n- Ý tưởng cũ", $text);
    }

    public function test_empty_idea_lets_claude_choose_the_concept()
    {
        Queue::fake([AnalyzeVocalsJob::class, PlanSeriesJob::class]);
        $series = $this->createMusicVideo(['idea' => null, 'singer' => null]);

        $this->assertSame('', $series->idea);
        $text = SeriesPlanner::userText($series);
        $this->assertStringContainsString('The artist has no idea yet', $text);
        $this->assertStringNotContainsString('The singer (main character) is named', $text);
    }

    private function fakeReference(array $captions): void
    {
        Http::fake([
            '*/youtube' => Http::response(['id' => 'yt1'], 202),
            '*/youtube/yt1' => Http::response([
                'state' => 'done', 'title' => 'Mưa Đêm (Official MV)', 'artist' => 'Ca Sĩ A', 'channel' => 'Ca Sĩ A Official', 'duration' => 200.0,
                'description' => 'MV chính thức', 'tags' => ['ballad'], 'language' => 'vi', 'captions_kind' => $captions ? 'manual' : null, 'captions' => $captions,
            ]),
        ]);
    }

    private function draftFor(array $captions): SongDraft
    {
        $this->fakeReference($captions);
        $this->actingAs($this->user)->post(route('song-drafts.store'), ['url' => 'https://www.youtube.com/watch?v=abc123', 'notes' => 'Chủ đề tình bạn'])
            ->assertSessionHasNoErrors();
        $draft = $this->user->songDrafts()->latest('id')->first();
        // Hàng đợi sync: lượt đầu gửi yêu cầu (release không làm gì), chạy tiếp tay để hỏi lại.
        (new ComposeSongDraftJob($draft))->handle(app(YoutubeReference::class));

        return $draft->fresh();
    }

    public function test_youtube_reference_is_validated_and_never_downloads_audio()
    {
        $this->actingAs($this->user)->post(route('song-drafts.store'), ['url' => 'https://example.com/song.mp3'])->assertSessionHasErrors('url');
        $draft = $this->draftFor([]);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/youtube') && $request['audio'] === false);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/audio'));
        $this->assertSame('ready', $draft->status, (string) $draft->error);
        $this->assertSame('Mưa Đêm (Official MV)', $draft->reference['title']);
        $this->assertSame('Chủ đề tình bạn', $draft->notes);
        // Không có bài tham khảo nào vào thư viện Âm thanh.
        $this->assertSame(0, $this->user->audioTracks()->count());
    }

    public function test_claude_profiles_the_reference_and_writes_an_original_song()
    {
        $draft = $this->draftFor([['start' => 10, 'end' => 13, 'text' => 'Mưa rơi trên phố vắng em đi một mình']]);

        $this->assertSame('V-pop ballad', $draft->profile['genre']);
        $this->assertSame(['intro', 'verse', 'chorus', 'outro'], array_column($draft->profile['structure'], 'section'));
        $this->assertSame('Nắng Sau Cơn Mưa', $draft->song['title']);
        $this->assertStringContainsString('[Chorus]', $draft->song['lyrics']);
        $this->assertFalse($draft->overlap['too_similar']);

        $profileText = SongWriter::profileText($draft);
        $this->assertStringContainsString('[10.0–13.0] Mưa rơi trên phố vắng em đi một mình', $profileText);
        $songText = SongWriter::songText($draft);
        $this->assertStringContainsString('Chủ đề tình bạn', $songText);
        // Bài mới không thấy lời / tên ca sĩ của bài tham khảo, chỉ hồ sơ.
        $this->assertStringNotContainsString('Mưa rơi trên phố vắng', $songText);
        $this->assertStringNotContainsString('Ca Sĩ A', $songText);
        $this->assertStringContainsString('Never copy', SongWriter::WRITE_SYSTEM);
        $this->assertSame('ballad, piano', SongWriter::cleanStyle('Ca Sĩ A, ballad, piano', $draft));
    }

    public function test_overlap_check_flags_copied_lines()
    {
        $reference = "Mưa rơi trên phố vắng em đi một mình\nTim anh vẫn đợi chờ";
        $copied = LyricsOverlap::check("[Verse]\nMưa rơi trên phố vắng em đi một mình\nNắng lên rồi em ơi", $reference);
        $this->assertTrue(LyricsOverlap::tooSimilar($copied));
        $this->assertSame(['Mưa rơi trên phố vắng em đi một mình'], $copied['lines']);

        $original = LyricsOverlap::check("[Chorus]\nNắng sau cơn mưa em lại mỉm cười\nTa vẫn còn đây", $reference);
        $this->assertFalse(LyricsOverlap::tooSimilar($original));
        $this->assertSame(0.0, $original['score']);
    }

    public function test_song_draft_can_be_edited_rewritten_and_turned_into_a_library_song()
    {
        Queue::fake([AnalyzeVocalsJob::class]);
        $draft = $this->draftFor([['start' => 10, 'end' => 13, 'text' => 'Mưa rơi trên phố vắng em đi một mình']]);

        // Sửa lời: chép lại câu của bài tham khảo thì bị cảnh báo; prompt phong cách bỏ tên ca sĩ tham khảo.
        $this->actingAs($this->user)->put(route('song-drafts.update', $draft), [
            'title' => 'Bài Mới', 'style_prompt' => 'ballad, Ca Sĩ A, piano', 'lyrics' => "[Verse]\nMưa rơi trên phố vắng em đi một mình",
        ])->assertSessionHas('status', fn ($s) => str_contains($s, 'còn giống'));
        $this->assertTrue($draft->fresh()->overlap['too_similar']);
        $this->assertSame('ballad, piano', $draft->fresh()->song['style_prompt']);

        $this->actingAs($this->user)->post(route('song-drafts.rewrite', $draft), ['notes' => 'vui hơn'])->assertSessionHasNoErrors();
        $draft->refresh();
        $this->assertSame('ready', $draft->status);
        $this->assertSame('vui hơn', $draft->notes);
        $this->assertFalse($draft->overlap['too_similar']);

        // Người khác không sửa được.
        $this->actingAs(User::factory()->create())->post(route('song-drafts.rewrite', $draft))->assertForbidden();

        // fal chưa có khoá: không tạo nhạc qua API được.
        $this->actingAs($this->user)->post(route('song-drafts.generate', $draft), ['provider' => 'fal'])->assertSessionHasErrors('provider');

        // Tự tạo nhạc ở công cụ ngoài rồi tải lên: thành bài trong thư viện, kèm lời, ý tưởng MV, ca sĩ.
        $this->actingAs($this->user)->post(route('song-drafts.upload', $draft), ['song' => UploadedFile::fake()->create('new.mp3', 300, 'audio/mpeg')])
            ->assertSessionHasErrors('rights');
        $this->actingAs($this->user)->post(route('song-drafts.upload', $draft), ['song' => UploadedFile::fake()->create('new.mp3', 300, 'audio/mpeg'), 'rights' => 1])
            ->assertSessionHasNoErrors();
        $track = $draft->fresh()->audioTrack;
        $this->assertSame('Nắng Sau Cơn Mưa', $track->name);
        $this->assertSame('vi', $track->language);
        $this->assertStringContainsString('[Chorus]', $track->lyrics);
        $this->assertSame('song_draft', $track->song_info['source']);
        $this->assertNotEmpty($track->song_info['mv_idea']);
        $this->assertSame('V-pop ballad', $track->song_info['analysis']['genre']);
        Queue::assertPushed(AnalyzeVocalsJob::class);

        $this->actingAs($this->user)->get(route('music-videos.create'))->assertInertia(fn (Assert $page) => $page
            ->where('drafts.0.audio_track_id', $track->id)
            ->where('drafts.0.reference.title', 'Mưa Đêm (Official MV)')
            ->where('songs.0.mv_idea', $track->song_info['mv_idea'])
            ->where('musicProviders.fal.available', false));
    }

    public function test_fal_music_generation_sends_only_new_lyrics_and_style()
    {
        config(['videoaff.fal.key' => 'test-key']);
        Queue::fake([AnalyzeVocalsJob::class]);
        $draft = $this->draftFor([]);
        Http::fake([
            'https://queue.fal.run/fal-ai/ace-step' => Http::response(['request_id' => 'req1']),
            'https://queue.fal.run/fal-ai/ace-step/requests/req1/status' => Http::response(['status' => 'COMPLETED']),
            'https://queue.fal.run/fal-ai/ace-step/requests/req1' => Http::response(['audio' => ['url' => 'https://cdn.fal.media/song.wav']]),
            'https://cdn.fal.media/song.wav' => Http::response('RIFFfake', 200, ['Content-Type' => 'audio/wav']),
        ]);

        $this->actingAs($this->user)->post(route('song-drafts.generate', $draft), ['provider' => 'fal'])->assertSessionHasNoErrors();
        (new GenerateSongJob($draft))->handle();

        Http::assertSent(fn ($request) => $request->url() === 'https://queue.fal.run/fal-ai/ace-step'
            && $request['lyrics'] === $draft->song['lyrics'] && $request['tags'] === $draft->song['style_prompt'] && $request['duration'] === 75
            && ! str_contains(json_encode($request->data()), 'Ca Sĩ A'));
        $draft->refresh();
        $this->assertSame('done', $draft->music_status);
        $this->assertSame('RIFFfake', Storage::disk(config('videoaff.disk'))->get($draft->audioTrack->path));
        $this->assertStringEndsWith('.wav', $draft->audioTrack->path);
    }

    public function test_song_analysis_can_be_edited_and_feeds_ideas_and_shot_writing()
    {
        $track = $this->track();
        $this->actingAs($this->user)->post(route('music-videos.songs.analyze', $track))->assertSessionHasNoErrors();
        $this->assertSame('ready', $track->fresh()->song_info['analysis_status']);

        $this->actingAs($this->user)->put(route('music-videos.songs.analysis', $track), [
            'genre' => 'Indie pop', 'tempo_bpm' => 96, 'tempo_feel' => 'mid-tempo', 'mood' => 'Hopeful',
            'themes' => ['rain', 'light'], 'summary' => 'Một bài về hy vọng.',
            'structure' => [['section' => 'verse', 'start' => 0, 'end' => 12], ['section' => 'chorus', 'start' => 12, 'end' => 21]],
        ])->assertSessionHasNoErrors();
        $analysis = $track->fresh()->song_info['analysis'];
        $this->assertSame('Indie pop', $analysis['genre']);
        $this->assertSame(96, $analysis['tempo_bpm']);
        $this->actingAs($this->user)->put(route('music-videos.songs.analysis', $track), [
            'structure' => [['section' => 'chorus', 'start' => 10, 'end' => 5]],
        ])->assertSessionHasErrors('structure.0.end');
        // Bài hát của người khác.
        $other = User::factory()->create()->audioTracks()->create(['name' => 'X', 'path' => 'audio/x.mp3']);
        $this->actingAs($this->user)->put(route('music-videos.songs.analysis', $other), ['genre' => 'x'])->assertForbidden();

        $brief = SongAnalysis::brief($analysis);
        $this->assertStringContainsString('Tempo: mid-tempo (~96 BPM)', $brief);
        $this->assertStringContainsString('Song analysis:', MusicVideoIdeas::text(['song_brief' => $brief]));

        $series = $this->createMusicVideo(['audio_track_id' => $track->id, 'song_start' => 2]);
        $context = MusicVideoWriter::context($series->episodes()->first());
        $this->assertStringContainsString('Genre: Indie pop', $context);
        // Cấu trúc tính từ đầu đoạn MV (bắt đầu ở giây 2).
        $this->assertStringContainsString("[0.0–10.0] verse\n[10.0–19.0] chorus", $context);
        $this->assertStringContainsString('Song analysis:', SeriesPlanner::userText($series));
    }

    public function test_music_video_list_shows_stage_progress_and_actions()
    {
        Queue::fake([AnalyzeVocalsJob::class]);
        $series = $this->createMusicVideo();
        $episode = $series->episodes()->first();
        $this->user->series()->create(['title' => 'Phim thường', 'idea' => 'x', 'genre' => 'comedy', 'age_min' => 6, 'age_max' => 12, 'made_for_kids' => true,
            'style' => 'x', 'episode_count' => 1, 'status' => 'bible_ready']);

        // Kế hoạch xong, ca sĩ chưa có ảnh: chờ bạn duyệt ca sĩ.
        $this->actingAs($this->user)->get(route('music-videos.index'))->assertInertia(fn (Assert $page) => $page
            ->component('music-videos/index')
            ->has('musicVideos', 1)
            ->where('musicVideos.0.song', 'Neon Rain')
            ->where('musicVideos.0.stage.key', 'singer')
            ->where('musicVideos.0.stage.group', 'action')
            ->where('musicVideos.0.continue_url', route('series.show', $series))
            ->where('musicVideos.0.style_label', 'Người thật kiểu phim điện ảnh'));
        // Danh sách phim hoạt hình không còn MV.
        $this->actingAs($this->user)->get(route('series.index'))->assertInertia(fn (Assert $page) => $page->has('series', 1)->where('series.0.title', 'Phim thường'));

        // Có ảnh ca sĩ, đã cắt shot, vẽ được một nửa khung hình.
        $series->characters()->first()->update(['main_image_path' => 'series/singer.png']);
        $this->actingAs($this->user)->post(route('series.episodes.write', ['series' => $series->id, 'episode' => $episode->id]));
        $shots = $episode->shots()->get();
        $shots->take(4)->each->update(['image_status' => 'ready', 'image_path' => 'series/frame.png']);
        $episode->update(['status' => 'storyboard']);
        $stage = MusicVideoStage::of($series->fresh()->load(['characters', 'episodes' => fn ($q) => $q->withCount(['shots',
            'shots as images_ready_count' => fn ($q) => $q->where('image_status', 'ready'), 'shots as videos_ready_count' => fn ($q) => $q->whereNotNull('video_path')])]));
        $this->assertEquals(['key' => 'keyframes', 'label' => 'Chờ vẽ khung hình (4/8)', 'group' => 'action', 'progress' => 38, 'episode_id' => $episode->id], $stage);

        // Đã ghép xong: xem / tải được.
        $episode->update(['status' => 'animated', 'render_status' => 'done', 'render_path' => 'series/mv.mp4']);
        $this->actingAs($this->user)->get(route('music-videos.index'))->assertInertia(fn (Assert $page) => $page
            ->where('musicVideos.0.stage.group', 'done')->where('musicVideos.0.stage.progress', 100)
            ->where('musicVideos.0.thumbnail_url', Storage::disk(config('videoaff.disk'))->url('series/frame.png'))
            ->has('musicVideos.0.video_url')->has('musicVideos.0.download_url')
            ->where('musicVideos.0.continue_url', route('series.episodes.show', ['series' => $series->id, 'episode' => $episode->id])));
    }

    public function test_music_video_can_be_renamed_duplicated_and_deleted()
    {
        $series = $this->createMusicVideo(['lip_sync' => 'full', 'aspect_ratio' => '9:16']);

        $this->actingAs($this->user)->put(route('music-videos.rename', $series), ['title' => 'Neon Rain MV'])->assertSessionHasNoErrors();
        $this->assertSame('Neon Rain MV', $series->fresh()->title);

        $this->actingAs($this->user)->post(route('music-videos.duplicate', $series))->assertRedirect();
        $copy = $this->user->series()->latest('id')->first();
        $this->assertNotSame($series->id, $copy->id);
        $this->assertSame('Neon Rain MV (bản sao)', $copy->title);
        $this->assertSame([$series->audio_track_id, $series->song_end_ms, $series->lyrics, '9:16'], [$copy->audio_track_id, $copy->song_end_ms, $copy->lyrics, $copy->aspect_ratio]);
        $this->assertSame('full', $copy->lipSyncMode());
        // Kế hoạch chạy lại cho bản sao (hàng đợi sync), không chép khung hình / clip.
        $this->assertSame('bible_ready', $copy->status->value);
        $this->assertSame(0, $copy->episodes()->first()->shots()->count());

        $other = User::factory()->create();
        $this->actingAs($other)->delete(route('music-videos.destroy', $series))->assertForbidden();
        $this->actingAs($this->user)->delete(route('music-videos.destroy', $series))->assertRedirect(route('music-videos.index'));
        $this->assertNull($series->fresh());
        $this->assertNotNull($copy->song->fresh());
        // Không phải MV thì không xử lý ở đây.
        $film = $this->user->series()->create(['title' => 'Phim', 'idea' => 'x', 'genre' => 'comedy', 'age_min' => 6, 'age_max' => 12, 'made_for_kids' => true,
            'style' => 'x', 'episode_count' => 1, 'status' => 'bible_ready']);
        $this->actingAs($this->user)->delete(route('music-videos.destroy', $film))->assertNotFound();
    }

    public function test_writing_cuts_shots_on_the_song_timeline_and_marks_lip_sync()
    {
        $series = $this->createMusicVideo();
        $episode = $series->episodes()->first();

        $lines = MusicVideoWriter::timedLyrics($series);
        $this->assertSame(['Walking alone tonight', 'the rain falls slow', 'Light me up', 'light me up again'], array_column($lines, 'text'));
        $this->assertSame(12.5, $lines[2]['start']);

        $this->actingAs($this->user)->post(route('series.episodes.write', ['series' => $series->id, 'episode' => $episode->id]))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'phân cảnh MV'));

        $episode->refresh();
        $this->assertSame('storyboard', $episode->status, (string) $episode->error);
        $shots = $episode->shots()->get();
        // Mẫu: shot 4 giây, nối liền từ 0 tới hết 30 giây.
        $this->assertSame(30000, $shots->sum('duration_ms'));
        $this->assertSame(30000, $episode->duration_ms);
        $this->assertSame(0, $shots[0]->song_ms);
        foreach ($shots->values() as $i => $shot) {
            $this->assertSame($i === 0 ? 0 : $shots[$i - 1]->song_ms + $shots[$i - 1]->duration_ms, $shot->song_ms);
        }
        $this->assertSame('Light me up', $shots[3]->lyric);
        // Chỉ điệp khúc: shot hát nhép đều ở đoạn chorus.
        $this->assertTrue($shots->contains('lip_sync', true));
        $this->assertTrue($shots->where('lip_sync', true)->every(fn ($shot) => $shot->sound_cue === 'chorus'));
        $this->assertTrue($shots->every('animate'));

        $shot = $shots->firstWhere('lip_sync', true)->setRelation('episode', $episode);
        $image = ShotPrompt::build($shot);
        $this->assertStringContainsString('official music video', $image);
        $this->assertStringContainsString('mouth clearly visible', $image);
        $this->assertStringContainsString('never write it in the image', $image);
        $clip = ShotClipPrompt::build($shot);
        $this->assertStringContainsString('The attached audio is the song during this clip', $clip);
        $this->assertStringContainsString($shot->lyric, $clip);
        $quiet = ShotClipPrompt::build($shots->firstWhere('lip_sync', false)->setRelation('episode', $episode));
        $this->assertStringContainsString('Nobody sings', $quiet);

        // Phong cách người thật: lời nhắn Muse không ép nét hoạt hình.
        $this->assertStringContainsString(FilmMuseImageGenerator::LIVE_ACTION_RULES, FilmMuseImageGenerator::message('VA-ABC123', $image));
        $this->assertStringContainsString(FilmMuseClipGenerator::LIVE_ACTION_RULES, FilmMuseClipGenerator::message('VA-ABC123', $clip));
        $this->assertStringNotContainsString('NOT live action', FilmMuseClipGenerator::message('VA-ABC123', $clip));
    }

    public function test_normalize_merges_short_shots_and_splits_long_ones()
    {
        Queue::fake([AnalyzeVocalsJob::class, PlanSeriesJob::class]);
        $series = $this->createMusicVideo(['lip_sync' => 'none']);
        $episode = $series->episodes()->create(['number' => 1, 'title' => 'MV', 'synopsis' => 'x']);
        $shot = fn (float $start, string $section = 'verse') => [
            'start' => $start, 'section' => $section, 'lyric' => 'Light me up', 'lip_sync' => true, 'characters' => [], 'location' => '',
            'camera' => 'close-up', 'camera_move' => 'static', 'action' => "Shot at {$start}", 'motion' => 'x',
        ];

        $result = MusicVideoWriter::normalize($episode, ['shots' => [$shot(1.0), $shot(1.5), $shot(5.0, 'chorus'), $shot(29.0)]]);

        // 1.0 → 0; 1.5 gộp; 5 → 30 dài 25 giây chia 3 shot nối nhau; 29 sát cuối bỏ.
        $this->assertSame([5000, 8333, 8333, 8334], array_column($result['shots'], 'duration_ms'));
        $this->assertSame([0, 5000, 13333, 21666], array_column($result['shots'], 'song_ms'));
        $this->assertSame([false, false, true, true], array_column($result['shots'], 'continues_previous'));
        // Không hát nhép: luôn false dù Claude đánh dấu.
        $this->assertSame([false, false, false, false], array_column($result['shots'], 'lip_sync'));
    }

    public function test_render_uses_the_song_and_lyric_subtitles()
    {
        $series = $this->createMusicVideo(['song_start' => 2]);
        $episode = $series->episodes()->first();
        $this->actingAs($this->user)->post(route('series.episodes.write', ['series' => $series->id, 'episode' => $episode->id]));

        $defaults = EpisodeRenderer::defaults($series);
        $this->assertTrue($defaults['song']);
        $this->assertFalse($defaults['title_card']);
        $this->assertFalse($defaults['sound_effects']);

        $plan = EpisodeRenderer::plan($episode->fresh(), $defaults);
        // Vào thẳng shot đầu: không thẻ tiêu đề / thẻ kết.
        $this->assertArrayHasKey('shot_id', $plan[0]);
        $this->assertArrayHasKey('shot_id', end($plan));
        $this->assertSame(28000, array_sum(array_column($plan, 'duration_ms')));
        // Lời theo mốc của đoạn đã chọn (bắt đầu ở giây 2).
        $srt = EpisodeRenderer::srt($episode->fresh(), $plan);
        $this->assertStringContainsString("1\n00:00:01,000 --> 00:00:02,450\nWalking alone tonight", $srt);
        $this->assertStringContainsString('Light me up', $srt);
    }
}
