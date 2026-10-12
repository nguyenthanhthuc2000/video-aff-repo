<?php

namespace App\Http\Controllers;

use App\Enums\SeriesStatus;
use App\Jobs\PlanSeriesJob;
use App\Models\Episode;
use App\Models\Series;
use App\Models\SeriesCharacter;
use App\Models\SeriesLocation;
use App\Services\Film\SeriesPlanner;
use App\Services\Film\Voices;
use App\Support\CreditPricing;
use App\Support\Credits;
use App\Support\Media;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Phim hoạt hình: danh sách, tạo từ ý tưởng (Claude lên kế hoạch chạy ngầm), xem và sửa kế hoạch bộ phim. */
class SeriesController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('series/index', [
            'series' => $request->user()->series()->withCount('episodes')->latest()->get()->map(fn (Series $series) => [
                'id' => $series->id,
                'title' => $series->title,
                'logline' => $series->bible['logline'] ?? null,
                'genre' => Series::GENRES[$series->genre] ?? $series->genre,
                'age_min' => $series->age_min,
                'age_max' => $series->age_max,
                'status' => $series->status->value,
                'status_label' => $series->status->label(),
                'is_busy' => $series->isBusy(),
                'episodes_count' => $series->episodes_count,
                'created_at' => $series->created_at,
            ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('series/create', [
            'genres' => Series::GENRES,
            'languages' => Series::SUBTITLE_LANGUAGES,
            'ages' => ['min' => Series::AGE_MIN, 'max' => Series::AGE_MAX],
            'defaultStyle' => Series::DEFAULT_STYLE,
            'estimate' => CreditPricing::aiEstimates()['film_plan'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'idea' => ['required', 'string', 'min:20', 'max:3000'],
            'genre' => ['required', Rule::in(array_keys(Series::GENRES))],
            'age_min' => ['required', 'integer', 'min:'.Series::AGE_MIN, 'max:'.Series::AGE_MAX],
            'age_max' => ['required', 'integer', 'gte:age_min', 'max:'.Series::AGE_MAX],
            'episode_count' => ['required', 'integer', 'min:1', 'max:52'],
            'episode_minutes' => ['required', 'integer', 'min:1', 'max:15'],
            'style' => ['nullable', 'string', 'max:500'],
            'subtitle_languages' => ['array'],
            'subtitle_languages.*' => [Rule::in(array_keys(Series::SUBTITLE_LANGUAGES))],
        ], [
            'age_max.gte' => 'Tuổi lớn nhất phải từ tuổi nhỏ nhất trở lên.',
            'idea.min' => 'Viết ý tưởng dài hơn một chút (ít nhất 20 ký tự): nhân vật chính, bối cảnh, mỗi tập xảy ra chuyện gì.',
        ], ['idea' => 'ý tưởng', 'age_min' => 'tuổi nhỏ nhất', 'age_max' => 'tuổi lớn nhất', 'episode_count' => 'số tập', 'episode_minutes' => 'độ dài tập']);

        if ($name = SeriesPlanner::blockedName($validated['idea'])) {
            return back()->withErrors(['idea' => "Ý tưởng nhắc \"{$name}\" là nhân vật / phim có bản quyền. Hãy tạo nhân vật riêng (vẫn có thể theo phong cách tương tự)."])->withInput();
        }
        if ($message = Credits::shortfall($request->user(), CreditPricing::aiEstimates()['film_plan'], 'lên kế hoạch bộ phim')) {
            return back()->withErrors(['idea' => $message])->withInput();
        }

        $series = $request->user()->series()->create([
            'title' => Str::limit(trim($validated['idea']), 60),
            'idea' => trim($validated['idea']),
            'genre' => $validated['genre'],
            'age_min' => $validated['age_min'],
            'age_max' => $validated['age_max'],
            'made_for_kids' => Series::madeForKids($validated['age_min']),
            'style' => filled($validated['style'] ?? null) ? trim($validated['style']) : Series::DEFAULT_STYLE,
            'episode_count' => $validated['episode_count'],
            'episode_seconds' => $validated['episode_minutes'] * 60,
            'subtitle_languages' => array_values(array_unique($validated['subtitle_languages'] ?? [])),
            'status' => SeriesStatus::Planning,
        ]);
        PlanSeriesJob::dispatch($series);

        return to_route('series.show', $series)->with('status', 'Claude đang lên kế hoạch bộ phim (nhân vật, bối cảnh, dàn ý các tập), mất khoảng 1–3 phút...');
    }

    public function show(Series $series): Response
    {
        $this->authorize('view', $series);
        $series->load(['characters', 'locations', 'episodes']);

        return Inertia::render('series/show', [
            'series' => [
                'id' => $series->id,
                'title' => $series->title,
                'idea' => $series->idea,
                'genre' => $series->genre,
                'genre_label' => Series::GENRES[$series->genre] ?? $series->genre,
                'age_min' => $series->age_min,
                'age_max' => $series->age_max,
                'made_for_kids' => $series->made_for_kids,
                'style' => $series->style,
                'episode_count' => $series->episode_count,
                'episode_seconds' => $series->episode_seconds,
                'subtitle_languages' => collect($series->subtitle_languages ?? [])->map(fn ($code) => Series::SUBTITLE_LANGUAGES[$code] ?? $code)->values(),
                'bible' => $series->bible,
                'status' => $series->status->value,
                'status_label' => $series->status->label(),
                'is_busy' => $series->isBusy(),
                'error' => $series->error,
                'can_replan' => ! $series->isBusy() && ! $series->hasLockedDesigns(),
                'characters' => $series->characters->map(fn (SeriesCharacter $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'role' => $c->role,
                    'role_label' => SeriesCharacter::ROLES[$c->role] ?? $c->role,
                    'age' => $c->age,
                    'personality' => $c->personality,
                    'appearance' => $c->appearance,
                    'voice_type' => $c->voice['type'] ?? null,
                    'image_url' => Media::url($c->main_image_path),
                    'locked' => $c->locked_at !== null,
                ]),
                'locations' => $series->locations->map(fn (SeriesLocation $l) => [
                    'id' => $l->id,
                    'name' => $l->name,
                    'description' => $l->description,
                    'image_url' => Media::url($l->image_path),
                    'locked' => $l->locked_at !== null,
                ]),
                'episodes' => $series->episodes->map(fn (Episode $e) => [
                    'id' => $e->id,
                    'number' => $e->number,
                    'title' => $e->title,
                    'synopsis' => $e->synopsis,
                    'lesson' => $e->lesson,
                    'status' => $e->status,
                    'status_label' => Episode::STATUSES[$e->status] ?? $e->status,
                ]),
            ],
            'roles' => SeriesCharacter::ROLES,
            'voiceTypes' => collect(Voices::TYPES)->map(fn ($voice) => $voice['label']),
        ]);
    }

    /** Sửa kế hoạch: tên phim, kinh thánh, nhân vật (chưa chốt), bối cảnh (chưa chốt), dàn ý tập. */
    public function update(Request $request, Series $series): RedirectResponse
    {
        $this->authorize('update', $series);
        if ($series->isBusy()) {
            return back()->with('status', 'Bộ phim đang chạy, đợi xong rồi sửa.');
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'bible.logline' => ['nullable', 'string', 'max:500'],
            'bible.premise' => ['nullable', 'string', 'max:2000'],
            'bible.world' => ['nullable', 'string', 'max:2000'],
            'bible.tone' => ['nullable', 'string', 'max:500'],
            'characters' => ['array'],
            'characters.*.id' => ['required', 'integer'],
            'characters.*.name' => ['required', 'string', 'max:80'],
            'characters.*.role' => ['required', Rule::in(array_keys(SeriesCharacter::ROLES))],
            'characters.*.age' => ['nullable', 'integer', 'min:0', 'max:120'],
            'characters.*.personality' => ['nullable', 'string', 'max:1000'],
            'characters.*.appearance' => ['required', 'string', 'max:1500'],
            'characters.*.voice_type' => ['required', Rule::in(array_keys(Voices::TYPES))],
            'locations' => ['array'],
            'locations.*.id' => ['required', 'integer'],
            'locations.*.name' => ['required', 'string', 'max:80'],
            'locations.*.description' => ['required', 'string', 'max:1000'],
            'episodes' => ['array'],
            'episodes.*.id' => ['required', 'integer'],
            'episodes.*.title' => ['required', 'string', 'max:200'],
            'episodes.*.synopsis' => ['required', 'string', 'max:2000'],
            'episodes.*.lesson' => ['nullable', 'string', 'max:500'],
        ], [], ['characters.*.appearance' => 'ngoại hình', 'characters.*.name' => 'tên nhân vật', 'episodes.*.synopsis' => 'tóm tắt tập']);

        $series->update([
            'title' => $validated['title'],
            'bible' => [...($series->bible ?? []), ...array_map(fn ($value) => trim((string) $value), $validated['bible'] ?? [])],
        ]);
        foreach ($validated['characters'] ?? [] as $data) {
            $character = $series->characters()->whereKey($data['id'])->first();
            // Nhân vật đã chốt thì không đổi ngoại hình (ảnh tham chiếu đã vẽ theo mô tả đó).
            $character?->update([
                'name' => $data['name'],
                'role' => $data['role'],
                'age' => $data['age'] ?? null,
                'personality' => $data['personality'] ?? null,
                ...($character->locked_at === null ? ['appearance' => $data['appearance']] : []),
                'voice' => ($character->voice['type'] ?? null) === $data['voice_type'] ? $character->voice : Voices::forType($data['voice_type']),
            ]);
        }
        foreach ($validated['locations'] ?? [] as $data) {
            $location = $series->locations()->whereKey($data['id'])->first();
            $location?->update(['name' => $data['name'], ...($location->locked_at === null ? ['description' => $data['description']] : [])]);
        }
        foreach ($validated['episodes'] ?? [] as $data) {
            $series->episodes()->whereKey($data['id'])->first()?->update([
                'title' => $data['title'], 'synopsis' => $data['synopsis'], 'lesson' => $data['lesson'] ?? null,
            ]);
        }

        return back()->with('status', 'Đã lưu kế hoạch bộ phim.');
    }

    /** Claude lên kế hoạch lại từ đầu (chỉ khi chưa chốt nhân vật / bối cảnh nào). */
    public function plan(Request $request, Series $series): RedirectResponse
    {
        $this->authorize('update', $series);
        if ($series->isBusy() || $series->hasLockedDesigns()) {
            return back()->with('status', 'Không lên kế hoạch lại được: bộ phim đang chạy hoặc đã chốt nhân vật / bối cảnh.');
        }
        if ($message = Credits::shortfall($request->user(), CreditPricing::aiEstimates()['film_plan'], 'lên kế hoạch bộ phim')) {
            return back()->with('status', $message);
        }

        $series->update(['status' => SeriesStatus::Planning, 'error' => null]);
        PlanSeriesJob::dispatch($series);

        return back()->with('status', 'Claude đang lên kế hoạch lại bộ phim...');
    }

    public function destroy(Series $series): RedirectResponse
    {
        $this->authorize('delete', $series);
        if ($series->isBusy()) {
            return back()->with('status', 'Bộ phim đang chạy, đợi xong rồi xoá.');
        }
        $series->delete();

        return to_route('series.index')->with('status', 'Đã xoá bộ phim.');
    }
}
