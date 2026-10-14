<?php

namespace App\Http\Controllers;

use App\Jobs\CorrectSpeakingJob;
use App\Jobs\RenderEnglishVideoJob;
use App\Jobs\WriteEnglishLessonJob;
use App\Models\EnglishLesson;
use App\Models\EnglishRecording;
use App\Models\User;
use App\Services\Audio\Transcriber;
use App\Services\English\Curriculum;
use App\Services\English\Pronunciation;
use App\Support\CreditPricing;
use App\Support\Credits;
use App\Support\Media;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Học tiếng Anh mỗi ngày (A2 → B1): lộ trình 84 bài, luyện đọc / nói có chấm điểm, video Shorts từ chính bài luyện. */
class EnglishController extends Controller
{
    public function index(Request $request): Response
    {
        $lessons = $request->user()->englishLessons()->withCount(['recordings' => fn ($q) => $q->where('status', 'done')])->get()->keyBy('day');
        // Ngày cần học tiếp: bài đầu tiên chưa luyện (chưa thu, chưa có video); soạn trước cả lộ trình thì vẫn đúng.
        $next = $lessons->first(fn (EnglishLesson $l) => $l->recordings_count === 0 && $l->video_path === null)?->day
            ?? min(Curriculum::TOTAL_DAYS, ((int) $lessons->keys()->max()) + 1);
        $missing = Curriculum::TOTAL_DAYS - $lessons->count();

        return Inertia::render('english/index', [
            'weeks' => collect(Curriculum::WEEKS)->map(fn ($week, $w) => [
                'number' => $w + 1,
                'theme' => $week['theme'],
                'theme_vi' => $week['theme_vi'],
                'grammar' => $week['grammar'],
                'days' => collect(range($w * 7 + 1, $w * 7 + 7))->map(function (int $day) use ($lessons) {
                    $info = Curriculum::day($day);
                    $lesson = $lessons->get($day);

                    return [
                        'day' => $day,
                        'kind' => $info['kind'],
                        'topic' => $info['topic'],
                        'topic_vi' => $info['topic_vi'],
                        'lesson_id' => $lesson?->id,
                        'status' => $lesson?->status,
                        'recordings' => (int) ($lesson?->recordings_count ?? 0),
                        'has_video' => $lesson?->video_path !== null,
                    ];
                }),
            ]),
            'next' => [...Curriculum::day($next), 'exists' => $lessons->has($next)],
            'stats' => [
                'done' => $lessons->filter(fn (EnglishLesson $l) => $l->video_path !== null || $l->recordings_count > 0)->count(),
                'videos' => $lessons->whereNotNull('video_path')->count(),
                'streak' => self::streak($request->user()->id),
                'total' => Curriculum::TOTAL_DAYS,
            ],
            'price' => CreditPricing::aiEstimates()['english_lesson'],
            'profile' => $request->user()->english_profile,
            'prepare' => [
                'missing' => $missing,
                'writing' => $lessons->where('status', 'writing')->count(),
                'failed' => $lessons->where('status', 'failed')->count(),
            ],
        ]);
    }

    /** Hồ sơ người học: Claude soạn câu theo đúng đời sống của người học (bài soạn sau mới áp dụng). */
    public function profile(Request $request): RedirectResponse
    {
        $validated = $request->validate(['english_profile' => ['nullable', 'string', 'max:2000']]);
        $request->user()->update(['english_profile' => trim((string) ($validated['english_profile'] ?? '')) ?: null]);

        return back()->with('status', 'Đã lưu thông tin về bạn. Các bài soạn từ giờ sẽ dùng thông tin này.');
    }

    /** Số ngày liên tiếp có luyện (có bản thu), tính tới hôm nay hoặc hôm qua. */
    private static function streak(int $userId): int
    {
        $days = EnglishRecording::whereHas('lesson', fn ($q) => $q->where('user_id', $userId))
            ->where('status', 'done')->pluck('created_at')
            ->map(fn (Carbon $at) => $at->copy()->timezone(config('app.timezone'))->toDateString())->unique()->flip();
        $day = now();
        if (! $days->has($day->toDateString())) {
            $day = $day->subDay();
        }
        $streak = 0;
        while ($days->has($day->toDateString())) {
            $streak++;
            $day = $day->subDay();
        }

        return $streak;
    }

    /** Soạn trước mọi bài chưa có của lộ trình (Claude soạn lần lượt theo thứ tự ngày để không lặp câu), cả bài soạn lỗi. */
    public function prepareAll(Request $request): RedirectResponse
    {
        $user = $request->user();
        $count = Curriculum::TOTAL_DAYS - $user->englishLessons()->whereIn('status', ['ready', 'writing'])->count();
        if ($count === 0) {
            return back()->with('status', 'Đã soạn đủ cả lộ trình.');
        }
        if ($message = Credits::shortfall($user, $count * CreditPricing::aiEstimates()['english_lesson'], "soạn {$count} bài")) {
            return back()->with('status', $message);
        }

        return back()->with('status', 'Claude đang soạn '.self::queueAll($user).' bài, lần lượt từng ngày (khoảng 1 phút mỗi bài). Bài nào xong là học được ngay.');
    }

    /** Tạo và xếp hàng soạn các bài chưa có / soạn lỗi theo thứ tự ngày; trả số bài. */
    public static function queueAll(User $user): int
    {
        $existing = $user->englishLessons()->get()->keyBy('day');
        $queued = 0;
        foreach (range(1, Curriculum::TOTAL_DAYS) as $day) {
            $lesson = $existing->get($day);
            if ($lesson !== null && $lesson->status !== 'failed') {
                continue;
            }
            $info = Curriculum::day($day);
            $lesson ??= $user->englishLessons()->make(['day' => $day]);
            $lesson->fill(['kind' => $info['kind'], 'level' => $info['level'], 'topic' => $info['topic'], 'topic_vi' => $info['topic_vi'], 'status' => 'writing', 'error' => null])->save();
            WriteEnglishLessonJob::dispatch($lesson);
            $queued++;
        }

        return $queued;
    }

    /** Bắt đầu bài của một ngày (bài kế tiếp, hoặc soạn lại bài đã có): Claude soạn, Kokoro đọc mẫu. */
    public function start(Request $request, int $day): RedirectResponse
    {
        $user = $request->user();
        $last = (int) $user->englishLessons()->max('day');
        $lesson = $user->englishLessons()->where('day', $day)->first();
        if ($day < 1 || $day > Curriculum::TOTAL_DAYS || ($lesson === null && $day !== $last + 1)) {
            return back()->with('status', 'Học lần lượt từng ngày: bài tiếp theo là Day '.($last + 1).'.');
        }
        if ($lesson?->status === 'writing') {
            return to_route('english.show', $lesson);
        }
        if ($message = Credits::shortfall($user, CreditPricing::aiEstimates()['english_lesson'], 'soạn bài')) {
            return back()->with('status', $message);
        }
        $info = Curriculum::day($day);
        if ($lesson !== null) {
            // Soạn lại: câu đổi nên bỏ các bản thu cũ.
            $lesson->recordings()->get()->each(fn (EnglishRecording $r) => Media::delete($r->path));
            $lesson->recordings()->delete();
        }
        $lesson ??= $user->englishLessons()->make(['day' => $day]);
        $lesson->fill(['kind' => $info['kind'], 'level' => $info['level'], 'topic' => $info['topic'], 'topic_vi' => $info['topic_vi'], 'status' => 'writing', 'error' => null])->save();
        WriteEnglishLessonJob::dispatch($lesson);

        return to_route('english.show', $lesson)->with('status', "Claude đang soạn bài Day {$day} (khoảng 1 phút)...");
    }

    public function show(Request $request, EnglishLesson $lesson): Response
    {
        $this->own($request, $lesson);
        $info = Curriculum::day($lesson->day);
        $recordings = $lesson->recordings()->latest('id')->get();
        $best = $lesson->bestRecordings();
        $content = $lesson->content ?? [];

        return Inertia::render('english/lesson', [
            'lesson' => [
                'id' => $lesson->id,
                'day' => $lesson->day,
                'kind' => $lesson->kind,
                'kind_label' => EnglishLesson::KINDS[$lesson->kind],
                'level' => $lesson->level,
                'topic' => $lesson->topic,
                'topic_vi' => $lesson->topic_vi,
                'week' => $info['week'],
                'theme_vi' => $info['theme_vi'],
                'grammar' => $info['grammar'],
                'status' => $lesson->status,
                'error' => $lesson->error,
                'sentences' => collect($content['sentences'] ?? [])->map(fn ($s, $i) => [
                    ...$s,
                    'audio_url' => Media::url($content['audio'][$i]['path'] ?? null),
                    'best' => isset($best[$i]) ? self::recording($best[$i], $s['en']) : null,
                    'attempts' => $recordings->where('item', $i)->count(),
                ])->values(),
                'sound_focus_vi' => $content['sound_focus_vi'] ?? null,
                'question' => $content['question'] ?? null,
                'prompt' => $content['prompt'] ?? null,
                'prompt_vi' => $content['prompt_vi'] ?? null,
                'phrases' => $content['phrases'] ?? [],
                'sample_answer' => $content['sample_answer'] ?? null,
                'speech' => $lesson->kind === 'speaking' && isset($best[0]) ? self::recording($best[0], null) : null,
                'feedback' => $lesson->feedback ? [
                    ...collect($lesson->feedback)->except(['audio'])->all(),
                    'corrections' => collect($lesson->feedback['corrections'] ?? [])->map(fn ($c, $i) => [...$c, 'audio_url' => Media::url($lesson->feedback['audio'][$i]['path'] ?? null)])->values(),
                    'stale' => ($lesson->feedback['recording_id'] ?? null) !== ($best[0]->id ?? null),
                ] : null,
                'post' => $content['post'] ?? null,
                'video' => [
                    'status' => $lesson->video_status,
                    'error' => $lesson->video_error,
                    'url' => Media::url($lesson->video_path),
                    'thumbnail_url' => Media::url($lesson->thumbnail_path),
                    'rendered_at' => $lesson->video_rendered_at,
                    'music_track_id' => $lesson->video_options['music_track_id'] ?? null,
                ],
            ],
            'musicTracks' => $request->user()->audioTracks()->latest()->get(['id', 'name']),
            'pricing' => ['write' => CreditPricing::aiEstimates()['english_lesson'], 'video' => RenderEnglishVideoJob::price()],
            'previous' => $request->user()->englishLessons()->where('day', $lesson->day - 1)->value('id'),
            'next' => $request->user()->englishLessons()->where('day', $lesson->day + 1)->value('id'),
        ]);
    }

    private static function recording(EnglishRecording $recording, ?string $expected): array
    {
        return [
            'id' => $recording->id,
            'url' => Media::url($recording->path),
            'score' => $recording->score,
            'transcript' => $recording->transcript,
            'words' => $expected !== null ? Pronunciation::score($expected, $recording->words['heard'] ?? [])['words'] : [],
            'duration_ms' => $recording->duration_ms,
        ];
    }

    /** Lưu một lần đọc / nói (ghi âm trên trình duyệt), Whisper chép lời ngay, chấm điểm câu. Miễn phí. */
    public function record(Request $request, EnglishLesson $lesson, Transcriber $transcriber): RedirectResponse
    {
        $this->own($request, $lesson);
        $count = count($lesson->content['sentences'] ?? []);
        $validated = $request->validate([
            'item' => ['required', 'integer', 'min:0', 'max:'.max(0, $count - 1)],
            'audio' => ['required', 'file', 'max:20480'],
        ]);
        if ($lesson->status !== 'ready') {
            return back()->with('status', 'Bài chưa soạn xong.');
        }
        set_time_limit(240);
        $file = $request->file('audio');
        $extension = Str::lower($file->getClientOriginalExtension() ?: 'webm');
        $path = $file->storeAs("english/{$lesson->user_id}/day-{$lesson->day}", 'rec-'.$validated['item'].'-'.Str::lower(Str::random(8)).'.'.$extension, config('videoaff.disk'));
        $recording = $lesson->recordings()->create(['item' => $validated['item'], 'path' => $path, 'status' => 'pending']);
        try {
            $heard = $transcriber->transcribe((string) Media::disk()->get($path));
        } catch (RuntimeException $e) {
            $recording->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);

            return back()->with('status', $e->getMessage());
        }
        $score = $lesson->kind === 'sentences' ? Pronunciation::score($lesson->content['sentences'][$validated['item']]['en'], $heard['words'])['score'] : null;
        $recording->update([
            'status' => 'done', 'transcript' => $heard['text'], 'duration_ms' => $heard['duration_ms'],
            'words' => ['heard' => $heard['words']], 'segments' => $heard['segments'], 'score' => $score,
        ]);
        if (trim($heard['text']) === '') {
            return back()->with('status', 'Không nghe thấy tiếng nói. Kiểm tra micro rồi thử lại.');
        }

        return back()->with('status', $score !== null ? 'Câu '.($validated['item'] + 1).": {$score}/10" : 'Đã lưu bài nói. Bấm "Claude sửa lỗi" để xem cần sửa gì.');
    }

    public function destroyRecording(Request $request, EnglishLesson $lesson, EnglishRecording $recording): RedirectResponse
    {
        $this->own($request, $lesson);
        abort_unless($recording->english_lesson_id === $lesson->id, 404);
        Media::delete($recording->path);
        $recording->delete();

        return back()->with('status', 'Đã xoá bản thu.');
    }

    /** Bài nói 30 giây: Claude sửa lỗi từ lời đã chép của lần nói tốt nhất. */
    public function correct(Request $request, EnglishLesson $lesson): RedirectResponse
    {
        $this->own($request, $lesson);
        if ($lesson->kind !== 'speaking' || ! isset($lesson->bestRecordings()[0])) {
            return back()->with('status', 'Thu bài nói trước rồi mới sửa lỗi.');
        }
        if (($lesson->feedback['status'] ?? null) === 'correcting') {
            return back()->with('status', 'Claude đang sửa bài nói.');
        }
        if ($message = Credits::shortfall($request->user(), CreditPricing::aiEstimates()['english_lesson'], 'sửa bài nói')) {
            return back()->with('status', $message);
        }
        $lesson->update(['feedback' => [...($lesson->feedback ?? []), 'status' => 'correcting', 'error' => null]]);
        CorrectSpeakingJob::dispatch($lesson);

        return back()->with('status', 'Claude đang sửa bài nói (khoảng 1 phút)...');
    }

    /** Dựng video Shorts dọc từ bài học + giọng của bạn. */
    public function render(Request $request, EnglishLesson $lesson): RedirectResponse
    {
        $this->own($request, $lesson);
        $validated = $request->validate([
            'music_track_id' => ['nullable', 'integer', Rule::exists('audio_tracks', 'id')->where('user_id', $request->user()->id)],
            'music_volume' => ['nullable', 'integer', 'min:0', 'max:40'],
        ]);
        if ($lesson->status !== 'ready') {
            return back()->with('status', 'Bài chưa soạn xong.');
        }
        if ($lesson->video_status === 'rendering') {
            return back()->with('status', 'Video đang được dựng.');
        }
        if ($lesson->kind === 'speaking' && ! isset($lesson->bestRecordings()[0])) {
            return back()->with('status', 'Thu bài nói trước rồi mới dựng video.');
        }
        if ($message = Credits::shortfall($request->user(), RenderEnglishVideoJob::price(), 'dựng video')) {
            return back()->with('status', $message);
        }
        $run = Str::lower(Str::random(8));
        $lesson->update(['video_status' => 'rendering', 'video_error' => null, 'video_options' => [...$validated, 'run' => $run]]);
        RenderEnglishVideoJob::dispatch($lesson, $run);

        return back()->with('status', 'Đang dựng video (dưới 1 phút)...');
    }

    public function download(Request $request, EnglishLesson $lesson): StreamedResponse|RedirectResponse
    {
        $this->own($request, $lesson);
        if ($lesson->video_path === null) {
            return back()->with('status', 'Chưa có video.');
        }

        return Media::disk()->download($lesson->video_path, "english-day-{$lesson->day}.mp4");
    }

    public function thumbnail(Request $request, EnglishLesson $lesson): StreamedResponse|RedirectResponse
    {
        $this->own($request, $lesson);
        if ($lesson->thumbnail_path === null) {
            return back()->with('status', 'Chưa có ảnh bìa: dựng video trước.');
        }

        return Media::disk()->download($lesson->thumbnail_path, "english-day-{$lesson->day}-thumbnail.jpg");
    }

    private function own(Request $request, EnglishLesson $lesson): void
    {
        abort_unless($lesson->user_id === $request->user()->id, 404);
    }
}
