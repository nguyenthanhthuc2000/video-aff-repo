<?php

namespace App\Http\Controllers;

use App\Enums\SeriesStatus;
use App\Jobs\PlanSeriesJob;
use App\Jobs\WriteEpisodeJob;
use App\Models\Episode;
use App\Models\Series;
use App\Models\SeriesCharacter;
use App\Models\SeriesLocation;
use App\Services\Film\EpisodeList;
use App\Services\Film\EpisodeWriter;
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
            'stylePresets' => collect(Series::STYLE_PRESETS)->map(fn ($p) => $p['label']),
            'formats' => Series::FORMATS,
            'clipBudgets' => Series::CLIP_BUDGETS,
            'kinds' => Series::KINDS,
            'shortAudience' => Series::SHORT_AUDIENCE,
            'estimate' => CreditPricing::aiEstimates()['film_plan'],
            'lengths' => Series::EPISODE_LENGTHS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'format' => ['nullable', Rule::in(array_keys(Series::FORMATS))],
            'idea' => ['required', 'string', 'min:20', 'max:8000'],
            // Truyện ngắn: người xem, số clip AI mỗi tập, danh sách tập có sẵn (mỗi dòng một tập), kịch bản có sẵn của vài tập.
            'audience' => ['nullable', 'string', 'max:200'],
            'clip_budget' => ['nullable', 'integer', Rule::in(array_keys(Series::CLIP_BUDGETS))],
            'episode_list' => ['nullable', 'string', 'max:20000'],
            'briefs' => ['array', 'max:'.EpisodeList::MAX],
            'briefs.*.number' => ['required', 'integer', 'min:1', 'max:'.EpisodeList::MAX],
            'briefs.*.text' => ['required', 'string', 'max:8000'],
            'style_preset' => ['nullable', Rule::in(array_keys(Series::STYLE_PRESETS))],
            'genre' => ['required', Rule::in(array_keys(Series::GENRES))],
            'age_min' => ['required', 'integer', 'min:'.Series::AGE_MIN, 'max:'.Series::AGE_MAX],
            'age_max' => ['required', 'integer', 'gte:age_min', 'max:'.Series::AGE_MAX],
            'episode_count' => ['required', 'integer', 'min:1', 'max:'.EpisodeList::MAX],
            'episode_seconds' => ['nullable', 'integer', Rule::in(Series::EPISODE_LENGTHS)],
            'episode_minutes' => ['required_without:episode_seconds', 'nullable', 'integer', 'min:1', 'max:15'],
            // Nhân vật có sẵn ảnh (không bắt buộc): Claude xem ảnh để viết ngoại hình, ảnh thành ảnh chính của nhân vật.
            'characters' => ['array', 'max:6'],
            'characters.*.name' => ['required', 'string', 'max:80', 'distinct:ignore_case'],
            'characters.*.note' => ['nullable', 'string', 'max:300'],
            'characters.*.image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'style' => ['nullable', 'string', 'max:500'],
            'subtitle_languages' => ['array'],
            'subtitle_languages.*' => [Rule::in(array_keys(Series::SUBTITLE_LANGUAGES))],
        ], [
            'age_max.gte' => 'Tuổi lớn nhất phải từ tuổi nhỏ nhất trở lên.',
            'idea.min' => 'Viết ý tưởng dài hơn một chút (ít nhất 20 ký tự): nhân vật chính, bối cảnh, mỗi tập xảy ra chuyện gì.',
            'characters.*.name.required' => 'Nhập tên nhân vật.',
            'characters.*.name.distinct' => 'Hai nhân vật trùng tên.',
            'characters.*.image.required' => 'Chọn ảnh cho nhân vật.',
            'characters.*.image.image' => 'File phải là ảnh (jpg, png, webp).',
        ], ['idea' => 'ý tưởng', 'characters.*.image' => 'ảnh nhân vật', 'characters.*.name' => 'tên nhân vật', 'age_min' => 'tuổi nhỏ nhất', 'age_max' => 'tuổi lớn nhất', 'episode_count' => 'số tập', 'episode_minutes' => 'độ dài tập']);

        if ($name = SeriesPlanner::blockedName($validated['idea'])) {
            return back()->withErrors(['idea' => "Ý tưởng nhắc \"{$name}\" là nhân vật / phim có bản quyền. Hãy tạo nhân vật riêng (vẫn có thể theo phong cách tương tự)."])->withInput();
        }
        if ($message = Credits::shortfall($request->user(), CreditPricing::aiEstimates()['film_plan'], 'lên kế hoạch bộ phim')) {
            return back()->withErrors(['idea' => $message])->withInput();
        }

        $short = ($validated['format'] ?? 'episodic') === 'silent_short';
        // Danh sách tập có sẵn: số tập theo danh sách; kịch bản có sẵn gắn theo số tập.
        $briefs = collect($validated['briefs'] ?? [])->keyBy('number');
        $list = collect(EpisodeList::parse($validated['episode_list'] ?? null))->map(fn ($e, $i) => [...$e, 'brief' => filled($briefs[$i + 1]['text'] ?? null) ? trim($briefs[$i + 1]['text']) : null])->all();
        $preset = Series::STYLE_PRESETS[$validated['style_preset'] ?? ($short ? '3d' : '2d')]['style'];

        $series = $request->user()->series()->create([
            'title' => Str::limit(trim(ltrim(strtok(trim($validated['idea']), "\n"), '# ')), 60),
            'idea' => trim($validated['idea']),
            'format' => $short ? 'silent_short' : 'episodic',
            'genre' => $validated['genre'],
            'age_min' => $validated['age_min'],
            'age_max' => $validated['age_max'],
            // Truyện ngắn cho người xem quốc tế 16–34: không đánh dấu "Dành cho trẻ em".
            'audience' => $short ? (filled($validated['audience'] ?? null) ? trim($validated['audience']) : Series::SHORT_AUDIENCE) : null,
            'made_for_kids' => $short ? false : Series::madeForKids($validated['age_min']),
            'style' => filled($validated['style'] ?? null) ? trim($validated['style']) : $preset,
            'aspect_ratio' => $short ? '9:16' : '16:9',
            'episode_count' => $list !== [] ? count($list) : $validated['episode_count'],
            'episode_seconds' => $short ? 30 : ($validated['episode_seconds'] ?? $validated['episode_minutes'] * 60),
            'clip_budget' => $short ? ($validated['clip_budget'] ?? 3) : null,
            'seed_episodes' => $list !== [] ? $list : null,
            'subtitle_languages' => array_values(array_unique($validated['subtitle_languages'] ?? [])),
            'status' => SeriesStatus::Planning,
        ]);
        $seeds = collect($request->file('characters') ?? [])->map(fn ($files, $i) => [
            'name' => trim($validated['characters'][$i]['name']),
            'note' => trim((string) ($validated['characters'][$i]['note'] ?? '')),
            'path' => Media::store($files['image'], "series/{$series->id}/characters"),
        ])->values()->all();
        if ($seeds !== []) {
            $series->update(['seed_characters' => $seeds]);
        }
        PlanSeriesJob::dispatch($series);

        return to_route('series.show', $series)->with('status', ($seeds !== [] ? 'Claude đang xem ảnh '.count($seeds).' nhân vật và lên kế hoạch' : 'Claude đang lên kế hoạch').' bộ phim (nhân vật, bối cảnh, dàn ý các tập), mất khoảng 1–3 phút...');
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
                'format' => $series->format,
                'format_label' => Series::FORMATS[$series->format] ?? $series->format,
                'audience' => $series->audience,
                'clip_budget' => $series->silent() ? $series->clipBudget() : null,
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
                    'kind' => $e->kind,
                    'kind_label' => Series::KINDS[$e->kind] ?? null,
                    'has_brief' => filled($e->brief),
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
                'voice' => ($character->voice['type'] ?? null) === $data['voice_type'] ? $character->voice : Voices::assign($series, $data['voice_type'], $character),
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

    /** Claude viết kịch bản lô tập kế tiếp chưa viết (mặc định 5 tập, theo kế hoạch kênh: viết theo lô rồi duyệt tránh lặp motif). */
    public function writeBatch(Request $request, Series $series): RedirectResponse
    {
        $this->authorize('update', $series);
        $validated = $request->validate(['count' => ['nullable', 'integer', 'min:1', 'max:10']]);
        if ($series->isBusy() || $series->characters()->doesntExist()) {
            return back()->with('status', 'Bộ phim đang lên kế hoạch hoặc chưa có nhân vật.');
        }
        $episodes = $series->episodes()->whereIn('status', ['draft', 'failed'])->whereDoesntHave('shots')->limit($validated['count'] ?? 5)->get();
        if ($episodes->isEmpty()) {
            return back()->with('status', 'Mọi tập đã có kịch bản.');
        }
        $price = CreditPricing::aiEstimates()['film_episode'] * ($series->silent() ? 2 : max(2, (int) round($series->episode_seconds / EpisodeWriter::SEQUENCE_SECONDS)) + 1) * $episodes->count();
        if ($message = Credits::shortfall($request->user(), $price, "viết {$episodes->count()} tập")) {
            return back()->with('status', $message);
        }
        // Viết lần lượt (tập sau đọc được tóm tắt tập trước): mỗi tập chạy một job, cách nhau một chút.
        foreach ($episodes->values() as $i => $episode) {
            $run = (string) Str::uuid();
            $episode->update(['status' => 'writing', 'error' => null, 'outline' => ['run' => $run]]);
            WriteEpisodeJob::dispatch($episode, $run)->delay(now()->addSeconds($i * 20));
        }

        return back()->with('status', "Claude đang viết kịch bản {$episodes->count()} tập: ".$episodes->map(fn (Episode $e) => "tập {$e->number}")->implode(', ').'. Trang tập tự cập nhật.');
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
