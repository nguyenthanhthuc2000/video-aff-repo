<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateShotImageJob;
use App\Jobs\WriteEpisodeJob;
use App\Jobs\WriteSequenceJob;
use App\Models\Episode;
use App\Models\Series;
use App\Models\SeriesCharacter;
use App\Models\Shot;
use App\Services\Film\EpisodeWriter;
use App\Services\Film\ShotPrompt;
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
        if ($episode->status === 'writing' || $episode->shots()->where('image_status', 'pending')->exists()) {
            return back()->with('status', 'Tập đang được viết hoặc đang vẽ khung hình, đợi xong đã.');
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

    private static function queueImage(Shot $shot, ?string $notes = null): void
    {
        $shot->update(['image_status' => 'pending', 'request_id' => null, 'error' => null]);
        GenerateShotImageJob::dispatch($shot, ShotPrompt::build($shot, $notes));
    }
}
