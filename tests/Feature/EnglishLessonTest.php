<?php

namespace Tests\Feature;

use App\Jobs\RenderEnglishVideoJob;
use App\Jobs\WriteEnglishLessonJob;
use App\Models\User;
use App\Services\English\Curriculum;
use App\Services\English\LessonWriter;
use App\Services\English\Pronunciation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Học tiếng Anh mỗi ngày: lộ trình, soạn bài, thu giọng + chấm điểm, sửa bài nói, video Shorts. */
class EnglishLessonTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /** Lời "nghe được" của lần thu tiếp theo. */
    private string $heard = 'Hi my name is Nam';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('videoaff.disk'));
        $this->user = User::factory()->create();
        Http::fake([
            '*/tts' => fn () => Http::response(['base64' => base64_encode(self::wav(1.2)), 'duration' => 1.2, 'lines' => [['start' => 0.05, 'end' => 1.1]]]),
            '*/transcribe' => function () {
                $words = array_map(fn ($w, $i) => ['start' => 0.2 + $i * 0.3, 'end' => 0.45 + $i * 0.3, 'word' => $w, 'probability' => 0.9], explode(' ', $this->heard), array_keys(explode(' ', $this->heard)));

                return Http::response(['text' => $this->heard, 'duration' => 2.0, 'words' => $words, 'segments' => [['start' => 0.2, 'end' => 1.6, 'text' => $this->heard]]]);
            },
        ]);
    }

    private static function wav(float $seconds): string
    {
        $data = str_repeat("\0\0", (int) (24000 * $seconds));

        return 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16).'data'.pack('V', strlen($data)).$data;
    }

    private function audio(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('recording.webm', self::wav(2));
    }

    public function test_curriculum_has_84_days_with_weekly_speaking_and_levels()
    {
        $this->assertSame('sentences', Curriculum::day(1)['kind']);
        $this->assertSame('speaking', Curriculum::day(7)['kind']);
        $this->assertSame('A2', Curriculum::day(1)['level']);
        $this->assertSame('B1', Curriculum::day(84)['level']);
        $this->assertCount(Curriculum::TOTAL_DAYS, array_unique(array_map(fn ($d) => Curriculum::day($d)['topic'].$d, range(1, 84))));
    }

    public function test_pronunciation_marks_missing_and_unclear_words()
    {
        $result = Pronunciation::score('Hi, my name is Nam.', [
            ['word' => 'Hi', 'probability' => 0.9], ['word' => 'my', 'probability' => 0.2], ['word' => 'game', 'probability' => 0.9], ['word' => 'is', 'probability' => 0.9],
            ['word' => 'Nam.', 'probability' => 0.95],
        ]);
        $this->assertSame(['ok', 'unclear', 'wrong', 'ok', 'ok'], array_column($result['words'], 'mark'));
        $this->assertSame('Nam.', $result['words'][4]['word']);
        $this->assertSame(7, $result['score']);
    }

    public function test_daily_lesson_record_score_and_video()
    {
        $this->actingAs($this->user)->get(route('english.index'))->assertInertia(fn (Assert $page) => $page->component('english/index')->where('next.day', 1)->has('weeks', 12));
        // Phải học lần lượt.
        $this->actingAs($this->user)->post(route('english.start', 3))->assertSessionHas('status', fn ($s) => str_contains($s, 'Day 1'));

        $this->actingAs($this->user)->post(route('english.start', 1))->assertRedirect();
        $lesson = $this->user->englishLessons()->sole();
        $this->assertSame('ready', $lesson->status, (string) $lesson->error);
        $this->assertCount(5, $lesson->content['sentences']);
        $this->assertCount(5, $lesson->content['audio']);
        Storage::disk(config('videoaff.disk'))->assertExists($lesson->content['audio'][0]['path']);

        // Đọc câu 1: Whisper nghe được đúng → 10 điểm; lần sau đọc thiếu → điểm thấp, vẫn giữ lần cao nhất.
        $this->heard = 'Hi my name is Nam';
        $this->actingAs($this->user)->post(route('english.recordings.store', $lesson), ['item' => 0, 'audio' => $this->audio()])
            ->assertSessionHas('status', 'Câu 1: 10/10');
        $this->heard = 'Hi my';
        $this->actingAs($this->user)->post(route('english.recordings.store', $lesson), ['item' => 0, 'audio' => $this->audio()]);
        $this->assertSame(10, $lesson->bestRecordings()[0]->score);
        $this->actingAs($this->user)->get(route('english.show', $lesson))->assertInertia(fn (Assert $page) => $page
            ->where('lesson.sentences.0.best.score', 10)->where('lesson.sentences.0.attempts', 2)->where('lesson.sentences.1.best', null));

        // Người khác không xem / thu được.
        $other = User::factory()->create();
        $this->actingAs($other)->get(route('english.show', $lesson))->assertNotFound();
        $this->actingAs($other)->post(route('english.recordings.store', $lesson), ['item' => 0, 'audio' => $this->audio()])->assertNotFound();

        Queue::fake();
        $this->actingAs($this->user)->post(route('english.video', $lesson))->assertSessionHas('status');
        $lesson->refresh();
        $this->assertSame('rendering', $lesson->video_status);
        (new RenderEnglishVideoJob($lesson, $lesson->video_options['run']))->handle();
        $lesson->refresh();
        $this->assertSame('done', $lesson->video_status, (string) $lesson->video_error);
        Storage::disk(config('videoaff.disk'))->assertExists($lesson->video_path);
        $this->assertSame(1, $this->user->creditTransactions()->where('reference', 'like', 'english-video:%')->count());
        $this->actingAs($this->user)->get(route('english.video.download', $lesson))->assertDownload();
        $this->assertNotNull($lesson->thumbnail_path);
        $this->actingAs($this->user)->get(route('english.thumbnail', $lesson))->assertDownload();
        $this->actingAs($this->user)->get(route('english.index'))->assertInertia(fn (Assert $page) => $page->where('next.day', 2)->where('stats.done', 1)->where('stats.streak', 1)->where('stats.videos', 1));
    }

    public function test_prepare_whole_plan_in_day_order()
    {
        Queue::fake();
        $this->actingAs($this->user)->post(route('english.prepare'))->assertSessionHas('status', fn ($s) => str_contains($s, '84 bài'));
        $this->assertSame(84, $this->user->englishLessons()->where('status', 'writing')->count());
        Queue::assertPushed(WriteEnglishLessonJob::class, 84);
        $this->assertSame(range(1, 84), Queue::pushed(WriteEnglishLessonJob::class)->map(fn ($job) => $job->lesson->day)->values()->all());
        // Đang soạn thì không xếp hàng lại; bài soạn lỗi thì soạn lại.
        $this->actingAs($this->user)->post(route('english.prepare'))->assertSessionHas('status', 'Đã soạn đủ cả lộ trình.');
        $this->user->englishLessons()->where('day', 5)->update(['status' => 'failed']);
        $this->actingAs($this->user)->post(route('english.prepare'));
        Queue::assertPushed(WriteEnglishLessonJob::class, 85);
        $this->actingAs($this->user)->get(route('english.index'))->assertInertia(fn (Assert $page) => $page->where('next.day', 1)->where('prepare.missing', 0));
    }

    public function test_learner_profile_is_saved_and_sent_to_claude()
    {
        $this->actingAs($this->user)->put(route('english.profile'), ['english_profile' => 'Tên Thức, lập trình viên, sống ở TP.HCM.'])->assertSessionHas('status');
        $this->assertSame('Tên Thức, lập trình viên, sống ở TP.HCM.', $this->user->fresh()->english_profile);
        $lesson = $this->user->englishLessons()->create(['day' => 1, 'kind' => 'sentences', 'level' => 'A2', 'topic' => 'x', 'topic_vi' => 'x', 'status' => 'ready']);
        $this->assertStringContainsString('Tên Thức', LessonWriter::profile($lesson));
        $this->actingAs($this->user)->get(route('english.index'))->assertInertia(fn (Assert $page) => $page->where('profile', 'Tên Thức, lập trình viên, sống ở TP.HCM.'));
    }

    public function test_speaking_day_is_corrected_by_claude_and_becomes_a_video()
    {
        foreach (range(1, 6) as $day) {
            $info = Curriculum::day($day);
            $this->user->englishLessons()->create(['day' => $day, 'kind' => $info['kind'], 'level' => $info['level'], 'topic' => $info['topic'], 'topic_vi' => $info['topic_vi'], 'status' => 'ready', 'content' => ['sentences' => []]]);
        }
        $this->actingAs($this->user)->post(route('english.start', 7));
        $lesson = $this->user->englishLessons()->where('day', 7)->sole();
        $this->assertSame('speaking', $lesson->kind);
        $this->assertNotEmpty($lesson->content['prompt']);

        $this->actingAs($this->user)->post(route('english.correct', $lesson))->assertSessionHas('status', fn ($s) => str_contains($s, 'Thu bài nói'));
        $this->heard = 'I am live in Hanoi and I like games';
        $this->actingAs($this->user)->post(route('english.recordings.store', $lesson), ['item' => 0, 'audio' => $this->audio()]);
        $this->actingAs($this->user)->post(route('english.correct', $lesson));
        $lesson->refresh();
        $this->assertSame('ready', $lesson->feedback['status']);
        $this->assertNotEmpty($lesson->feedback['corrections']);
        $this->assertCount(count($lesson->feedback['corrections']), $lesson->feedback['audio']);

        Queue::fake();
        $this->actingAs($this->user)->post(route('english.video', $lesson));
        $lesson->refresh();
        (new RenderEnglishVideoJob($lesson, $lesson->video_options['run']))->handle();
        $this->assertSame('done', $lesson->fresh()->video_status, (string) $lesson->fresh()->video_error);
    }
}
