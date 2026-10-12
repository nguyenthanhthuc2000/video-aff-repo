<?php

namespace App\Http\Controllers;

use App\Jobs\AnimateShotJob;
use App\Jobs\GenerateShotImageJob;
use App\Jobs\RewriteShotJob;
use App\Jobs\SynthesizeShotAudioJob;
use App\Jobs\WriteEpisodeJob;
use App\Jobs\WriteSequenceJob;
use App\Models\Episode;
use App\Models\Series;
use App\Models\SeriesCharacter;
use App\Models\Shot;
use App\Services\Film\EpisodeWriter;
use App\Services\Film\ShotAudio;
use App\Services\Film\ShotPrompt;
use App\Services\Film\ShotRewriter;
use App\Support\CreditPricing;
use App\Support\Credits;
use App\Support\Media;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Trang một tập phim: thông tin tập, nhân vật xuất hiện, Claude viết tập thành shot, Muse vẽ khung hình từng shot. */
class EpisodeController extends Controller
{
    public function show(Series $series, Episode $episode): Response
    {
        $this->authorize('view', $series);
        $episodes = $series->episodes()->get(['id', 'number', 'title']);
        $index = $episodes->search(fn (Episode $e) => $e->id === $episode->id);
        $nav = fn (?Episode $e) => $e ? ['id' => $e->id, 'number' => $e->number, 'title' => $e->title] : null;
        $shots = $episode->shots()->with('location:id,name')->get();
        $characterMap = $series->characters()->get()->keyBy('id');
        $outline = $episode->outline ?? [];
        $sequenceCount = count($outline['sequences'] ?? []) ?: max(2, (int) round($series->episode_seconds / EpisodeWriter::SEQUENCE_SECONDS));

        return Inertia::render('series/episode', [
            'series' => [
                'id' => $series->id,
                'title' => $series->title,
                'episode_seconds' => $series->episode_seconds,
                'episode_count' => $episodes->count(),
            ],
            'episode' => [
                'id' => $episode->id,
                'number' => $episode->number,
                'title' => $episode->title,
                'synopsis' => $episode->synopsis,
                'lesson' => $episode->lesson,
                'character_ids' => $episode->character_ids ?? [],
                'status' => $episode->status,
                'status_label' => Episode::STATUSES[$episode->status] ?? $episode->status,
                'error' => $episode->error,
                'summary' => $episode->summary,
                'duration_ms' => (int) $shots->sum('duration_ms'),
                'sequences' => collect($outline['sequences'] ?? [])->map(fn ($s, $i) => [
                    'number' => $i + 1,
                    'title' => $s['title'],
                    'summary' => $s['summary'],
                    'seconds' => $s['seconds'],
                ])->values(),
                'written' => (int) ($outline['written'] ?? 0),
                'can_resume' => $episode->status === 'failed' && ($outline['written'] ?? 0) < count($outline['sequences'] ?? []) && isset($outline['run']),
                'shots' => $shots->map(fn (Shot $shot) => [
                    'id' => $shot->id,
                    'sequence' => $shot->sequence,
                    'position' => $shot->position,
                    'camera' => $shot->camera,
                    'action' => $shot->action,
                    'character_ids' => $shot->character_ids ?? [],
                    'location' => $shot->location?->name,
                    'dialogue' => $shot->dialogue ?? [],
                    'duration_ms' => $shot->duration_ms,
                    'continues_previous' => $shot->continues_previous,
                    'image_url' => Media::url($shot->image_path),
                    'image_status' => $shot->image_status,
                    'error' => $shot->error,
                    'rejected' => ShotRewriter::rejection($shot) !== null,
                    'audio_url' => Media::url($shot->audio_path),
                    // Lời / giọng đã đổi sau khi đọc: tiếng (và video) là bản cũ.
                    'audio_current' => ShotAudio::isCurrent($shot, $characterMap),
                    'has_dialogue' => ShotAudio::key($shot, $characterMap) !== null,
                    'clip_status' => $shot->clip_status,
                    'clip_error' => $shot->clip_error,
                    'video_url' => Media::url($shot->video_path),
                ]),
            ],
            'characters' => $series->characters()->get()->map(fn (SeriesCharacter $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'role_label' => SeriesCharacter::ROLES[$c->role] ?? $c->role,
                'image_url' => Media::url($c->main_image_path),
                'locked' => $c->locked_at !== null,
            ]),
            'cameras' => EpisodeWriter::CAMERAS,
            'pricing' => [
                'write' => CreditPricing::aiEstimates()['film_episode'] * ($sequenceCount + 1),
                'image' => CreditPricing::settings()['steps']['image'],
                'rewrite' => CreditPricing::aiEstimates()['film_episode'],
                'clip' => AnimateShotJob::price(),
            ],
            'previous' => $nav($index > 0 ? $episodes[$index - 1] : null),
            'next' => $nav($episodes[$index + 1] ?? null),
        ]);
    }

    public function update(Request $request, Series $series, Episode $episode): RedirectResponse
    {
        $this->authorize('update', $series);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'synopsis' => ['required', 'string', 'max:2000'],
            'lesson' => ['nullable', 'string', 'max:500'],
            'character_ids' => ['array'],
            'character_ids.*' => ['integer', Rule::exists('series_characters', 'id')->where('series_id', $series->id)],
        ], [], ['title' => 'tên tập', 'synopsis' => 'tóm tắt', 'character_ids.*' => 'nhân vật']);

        $episode->update([...$validated, 'character_ids' => array_values(array_unique(array_map('intval', $validated['character_ids'] ?? [])))]);

        return back()->with('status', 'Đã lưu tập.');
    }

    /** Claude viết tập: dàn ý rồi từng phân đoạn thành shot (viết lại thì thay toàn bộ shot cũ). "resume": viết tiếp từ phân đoạn lỗi. */
    public function write(Request $request, Series $series, Episode $episode): RedirectResponse
    {
        $this->authorize('update', $series);
        if ($episode->status === 'writing' || $episode->shots()->where(fn ($q) => $q->where('image_status', 'pending')->orWhere('clip_status', 'pending'))->exists()) {
            return back()->with('status', 'Tập đang được viết, đang vẽ khung hình hoặc đang làm clip, đợi xong đã.');
        }
        if ($series->characters()->doesntExist()) {
            return back()->with('status', 'Bộ phim chưa có nhân vật.');
        }
        $sequences = count($episode->outline['sequences'] ?? []) ?: max(2, (int) round($series->episode_seconds / EpisodeWriter::SEQUENCE_SECONDS));
        if ($message = Credits::shortfall($request->user(), CreditPricing::aiEstimates()['film_episode'] * ($sequences + 1), 'viết tập')) {
            return back()->with('status', $message);
        }

        $outline = $episode->outline ?? [];
        if ($request->boolean('resume') && $episode->status === 'failed' && isset($outline['run']) && ($outline['written'] ?? 0) < count($outline['sequences'] ?? [])) {
            $episode->update(['status' => 'writing', 'error' => null]);
            WriteSequenceJob::dispatch($episode, $outline['run'], (int) ($outline['written'] ?? 0));

            return back()->with('status', 'Claude đang viết tiếp từ phân đoạn '.(($outline['written'] ?? 0) + 1).'...');
        }

        $run = (string) Str::uuid();
        $episode->update(['status' => 'writing', 'error' => null, 'outline' => ['run' => $run]]);
        WriteEpisodeJob::dispatch($episode, $run);

        return back()->with('status', 'Claude đang viết dàn ý rồi từng phân đoạn của tập, mất vài phút...');
    }

    /** Muse vẽ khung hình cho mọi shot chưa có (hoặc lỗi). Nhân vật trong shot cần có ảnh chính. */
    public function keyframes(Request $request, Series $series, Episode $episode): RedirectResponse
    {
        $this->authorize('update', $series);
        if ($episode->status === 'writing') {
            return back()->with('status', 'Tập đang được viết, đợi xong đã.');
        }
        $shots = $episode->shots()->whereIn('image_status', ['none', 'failed'])->get();
        if ($shots->isEmpty()) {
            return back()->with('status', 'Mọi shot đã có khung hình (hoặc đang vẽ).');
        }

        $missing = SeriesCharacter::whereIn('id', $shots->pluck('character_ids')->flatten()->unique())->whereNull('main_image_path')->pluck('name');
        if ($missing->isNotEmpty()) {
            return back()->with('status', 'Chưa có ảnh chính cho: '.$missing->implode(', ').'. Mở trang nhân vật để vẽ hoặc tải ảnh rồi chốt.');
        }
        $price = CreditPricing::settings()['steps']['image'];
        if ($message = Credits::shortfall($request->user(), $price * $shots->count(), "vẽ {$shots->count()} khung hình")) {
            return back()->with('status', $message);
        }

        foreach ($shots as $shot) {
            self::queueImage($shot);
        }
        $episode->update(['status' => 'keyframing', 'error' => null]);

        return back()->with('status', "Muse đang vẽ {$shots->count()} khung hình (khoảng 1 phút mỗi ảnh, chạy song song vài ảnh một lúc).");
    }

    public function updateShot(Request $request, Series $series, Episode $episode, Shot $shot): RedirectResponse
    {
        $this->authorize('update', $series);
        if ($shot->clip_status === 'pending') {
            return back()->with('status', 'Shot đang làm video, đợi xong rồi sửa.');
        }
        $validated = $request->validate([
            'camera' => ['required', Rule::in(EpisodeWriter::CAMERAS)],
            'action' => ['required', 'string', 'max:1500'],
            'character_ids' => ['array', 'max:4'],
            'character_ids.*' => ['integer', Rule::exists('series_characters', 'id')->where('series_id', $series->id)],
            'dialogue' => ['array', 'max:6'],
            'dialogue.*.character_id' => ['nullable', 'integer', Rule::exists('series_characters', 'id')->where('series_id', $series->id)],
            'dialogue.*.line' => ['required', 'string', 'max:500'],
            'dialogue.*.emotion' => ['nullable', 'string', 'max:30'],
            'seconds' => ['required', 'numeric', 'min:1', 'max:15'],
        ], [], ['action' => 'hành động', 'dialogue.*.line' => 'lời thoại', 'seconds' => 'thời lượng']);

        $shot->update([
            'camera' => $validated['camera'],
            'action' => $validated['action'],
            'character_ids' => array_values(array_unique(array_map('intval', $validated['character_ids'] ?? []))),
            'dialogue' => array_values(array_map(fn ($d) => [
                'character_id' => isset($d['character_id']) ? (int) $d['character_id'] : null,
                'line' => trim($d['line']),
                'emotion' => filled($d['emotion'] ?? null) ? trim($d['emotion']) : null,
            ], $validated['dialogue'] ?? [])),
            'duration_ms' => (int) round($validated['seconds'] * 1000),
        ]);
        $episode->update(['duration_ms' => (int) $episode->shots()->sum('duration_ms')]);

        return back()->with('status', 'Đã lưu shot. Bấm "Vẽ lại" nếu muốn khung hình theo nội dung mới.');
    }

    /** Muse vẽ lại khung hình một shot (có thể kèm ghi chú). */
    public function regenerateShot(Request $request, Series $series, Episode $episode, Shot $shot): RedirectResponse
    {
        $this->authorize('update', $series);
        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);
        if ($shot->image_status === 'pending' || $episode->status === 'writing') {
            return back()->with('status', 'Shot đang vẽ hoặc tập đang được viết.');
        }
        $missing = ShotPrompt::characters($shot)->whereNull('main_image_path')->pluck('name');
        if ($missing->isNotEmpty()) {
            return back()->with('status', 'Chưa có ảnh chính cho: '.$missing->implode(', ').'.');
        }
        if ($message = Credits::shortfall($request->user(), CreditPricing::settings()['steps']['image'], 'vẽ khung hình')) {
            return back()->with('status', $message);
        }

        self::queueImage($shot, $validated['notes'] ?? null);
        $episode->update(['status' => 'keyframing']);

        return back()->with('status', 'Muse đang vẽ lại khung hình shot này...');
    }

    /** Muse từ chối: Claude viết lại cảnh của shot (bố cục khác, theo lý do từ chối) rồi Muse vẽ lại. */
    public function rewriteShot(Request $request, Series $series, Episode $episode, Shot $shot): RedirectResponse
    {
        $this->authorize('update', $series);
        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);
        if ($shot->image_status === 'pending' || $episode->status === 'writing') {
            return back()->with('status', 'Shot đang vẽ hoặc tập đang được viết.');
        }
        $missing = ShotPrompt::characters($shot)->whereNull('main_image_path')->pluck('name');
        if ($missing->isNotEmpty()) {
            return back()->with('status', 'Chưa có ảnh chính cho: '.$missing->implode(', ').'.');
        }
        $price = CreditPricing::settings()['steps']['image'] + CreditPricing::aiEstimates()['film_episode'];
        if ($message = Credits::shortfall($request->user(), $price, 'viết lại và vẽ khung hình')) {
            return back()->with('status', $message);
        }

        $reason = ShotRewriter::rejection($shot);
        $shot->update(['image_status' => 'pending', 'request_id' => null, 'error' => null]);
        RewriteShotJob::dispatch($shot, $reason, $validated['notes'] ?? null);
        $episode->update(['status' => 'keyframing']);

        return back()->with('status', 'Claude đang viết lại cảnh của shot, xong thì Muse vẽ lại...');
    }

    /** Đọc lời thoại mọi shot bằng giọng cố định của từng nhân vật (Kokoro chạy trên máy, không tốn Credits) để nghe thử. */
    public function voices(Series $series, Episode $episode): RedirectResponse
    {
        $this->authorize('update', $series);
        $characters = $series->characters()->get()->keyBy('id');
        $shots = $episode->shots()->where('clip_status', '!=', 'pending')->get()->reject(fn (Shot $shot) => ShotAudio::isCurrent($shot, $characters));
        if ($shots->isEmpty()) {
            return back()->with('status', 'Lời thoại mọi shot đã được đọc theo giọng hiện tại.');
        }
        foreach ($shots as $shot) {
            SynthesizeShotAudioJob::dispatch($shot);
        }

        return back()->with('status', "Đang đọc lời thoại {$shots->count()} shot (miễn phí, vài giây mỗi shot)...");
    }

    /** Muse làm clip cho mọi shot đã có khung hình mà chưa có video (hoặc lỗi), mỗi shot ghép với lời thoại thành video. */
    public function clips(Request $request, Series $series, Episode $episode): RedirectResponse
    {
        $this->authorize('update', $series);
        if ($episode->status === 'writing') {
            return back()->with('status', 'Tập đang được viết, đợi xong đã.');
        }
        $shots = $episode->shots()->where('image_status', 'ready')->whereIn('clip_status', ['none', 'failed'])->get();
        if ($shots->isEmpty()) {
            return back()->with('status', $episode->shots()->where('image_status', '!=', 'ready')->exists()
                ? 'Shot cần có khung hình trước khi làm video. Bấm "Vẽ khung hình" trước.'
                : 'Mọi shot đã có video (hoặc đang làm).');
        }
        if ($message = Credits::shortfall($request->user(), AnimateShotJob::price() * $shots->count(), "làm {$shots->count()} clip")) {
            return back()->with('status', $message);
        }

        foreach ($shots as $shot) {
            self::queueClip($shot);
        }
        $episode->update(['status' => 'animating', 'error' => null]);

        return back()->with('status', "Muse đang làm video {$shots->count()} shot (đọc lời thoại trước, rồi làm clip khớp môi; vài phút mỗi shot, chạy song song vài shot).");
    }

    /** Làm lại video một shot (có thể kèm ghi chú chuyển động). */
    public function clip(Request $request, Series $series, Episode $episode, Shot $shot): RedirectResponse
    {
        $this->authorize('update', $series);
        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);
        if ($shot->clip_status === 'pending' || $episode->status === 'writing') {
            return back()->with('status', 'Shot đang làm video hoặc tập đang được viết.');
        }
        if ($shot->image_status !== 'ready') {
            return back()->with('status', 'Shot cần có khung hình trước.');
        }
        if ($message = Credits::shortfall($request->user(), AnimateShotJob::price(), 'làm clip shot')) {
            return back()->with('status', $message);
        }

        self::queueClip($shot, $validated['notes'] ?? null);
        $episode->update(['status' => 'animating']);

        return back()->with('status', 'Muse đang làm video shot này...');
    }

    private static function queueClip(Shot $shot, ?string $notes = null): void
    {
        $shot->update(['clip_status' => 'pending', 'clip_request_id' => null, 'clip_error' => null]);
        AnimateShotJob::dispatch($shot, $notes);
    }

    private static function queueImage(Shot $shot, ?string $notes = null): void
    {
        $shot->update(['image_status' => 'pending', 'request_id' => null, 'error' => null]);
        GenerateShotImageJob::dispatch($shot, ShotPrompt::build($shot, $notes));
    }
}
