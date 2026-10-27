<?php

namespace App\Http\Controllers;

use App\Jobs\CheckTopicShortFactsJob;
use App\Jobs\FindSceneImagesJob;
use App\Jobs\FindTopicSceneImageJob;
use App\Jobs\GenerateCharacterPortraitJob;
use App\Jobs\GenerateTopicSceneImageJob;
use App\Jobs\PlanTopicSeriesJob;
use App\Jobs\ProcessMangaScanJob;
use App\Jobs\RenderTopicShortJob;
use App\Jobs\ResearchTopicJob;
use App\Jobs\WriteTopicShortJob;
use App\Jobs\WriteTopicShortPublishJob;
use App\Models\AudioTrack;
use App\Models\CharacterPortrait;
use App\Models\MangaPanel;
use App\Models\TopicShort;
use App\Models\TopicShortScene;
use App\Services\Film\EpisodeRenderer;
use App\Services\TopicShort\CoverImage;
use App\Services\TopicShort\MangaPage;
use App\Services\TopicShort\ShortVideo;
use App\Services\TopicShort\ShortWriter;
use App\Services\TopicShort\TopicResearcher;
use App\Support\BuiltinMusic;
use App\Support\CreditPricing;
use App\Support\Credits;
use App\Support\DragonBall;
use App\Support\Media;
use App\Support\Pronounce;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Shorts theo chủ đề: nhập chủ đề → Claude kể chuyện → ảnh wiki / Muse / tải lên → giọng AI → video 9:16. */
class TopicShortController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->list($request, false);
    }

    /** Menu "Video truyện": short có tư liệu thật (research → duyệt → kịch bản có nguồn), series nhiều phần. */
    public function stories(Request $request): Response
    {
        return $this->list($request, true);
    }

    private function list(Request $request, bool $story): Response
    {
        $shorts = $request->user()->topicShorts()->where('use_research', $story)->latest()->withCount('scenes')->get();

        return Inertia::render('topic-shorts/index', [
            'shorts' => $shorts->map(fn (TopicShort $short) => [
                'id' => $short->id,
                'topic' => $short->topic,
                'title' => $short->title,
                'language' => $short->language,
                'status' => $short->status,
                'video_status' => $short->video_status,
                'scenes_count' => $short->scenes_count,
                'use_research' => $short->use_research,
                'part' => $short->part,
                'series_parts' => $short->series_parts,
                'created_at' => $short->created_at,
            ]),
            'languages' => TopicShort::LANGUAGES,
            'styles' => TopicShort::STYLES,
            'image_styles' => TopicShort::IMAGE_STYLES,
            'wikis' => collect(config('videoaff.topic_shorts.wikis'))->map(fn ($wiki) => $wiki['label']),
            'max_series_parts' => TopicShort::MAX_SERIES_PARTS,
            'story' => $story,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'topic' => ['required', 'string', 'min:5', 'max:300'],
            'language' => ['required', Rule::in(array_keys(TopicShort::LANGUAGES))],
            'style' => ['required', Rule::in(array_keys(TopicShort::STYLES))],
            'image_style' => ['required', Rule::in(array_keys(TopicShort::IMAGE_STYLES))],
            'target_seconds' => ['required', 'integer', 'min:30', 'max:60'],
            'wiki' => ['required', Rule::in(array_keys(config('videoaff.topic_shorts.wikis')))],
            'use_research' => ['boolean'],
            'research_notes' => ['nullable', 'string', 'max:2000'],
            'series_parts' => ['nullable', 'integer', 'min:1', 'max:'.TopicShort::MAX_SERIES_PARTS],
        ], [], ['topic' => 'chủ đề', 'target_seconds' => 'độ dài', 'series_parts' => 'số phần']);

        $research = (bool) ($validated['use_research'] ?? false);
        $short = $request->user()->topicShorts()->create([
            ...$validated,
            'use_research' => $research,
            // Series chỉ có khi có tư liệu (các phần dùng chung bản tư liệu).
            'series_parts' => $research ? (int) ($validated['series_parts'] ?? 1) : 1,
            'status' => $research ? 'researching' : 'writing',
        ]);
        if ($research) {
            ResearchTopicJob::dispatch($short);

            return to_route('topic-shorts.show', $short)->with('status', 'Claude đang tìm tư liệu trên web (vài phút)...');
        }
        WriteTopicShortJob::dispatch($short);

        return to_route('topic-shorts.show', $short)->with('status', 'Claude đang viết lời kể...');
    }

    public function show(Request $request, TopicShort $short): Response
    {
        $this->authorize('view', $short);
        $scenes = $short->scenes()->get();
        $library = CharacterPortrait::where('user_id', $short->user_id)->where('status', 'ready')->whereNotNull('image_path')
            ->whereIn('character', $scenes->flatMap(fn ($scene) => array_column($scene->panels ?? [], 'character'))->unique())->get();
        $missing = MangaPage::missing($scenes, $short->user_id);
        $mangaPanels = MangaPanel::where('user_id', $short->user_id)->with('scan:id,chapter,name')
            ->whereIn('id', $scenes->flatMap(fn ($scene) => $scene->manga_panels ?? [])->unique())->get()->keyBy('id');
        $words = $scenes->sum(fn ($scene) => count(preg_split('/\s+/u', trim($scene->narration), -1, PREG_SPLIT_NO_EMPTY) ?: []));
        $estimatedMs = (int) round($words / ShortWriter::WORDS_PER_SECOND[$short->language === 'en' ? 'en' : 'vi'] * 1000);

        return Inertia::render('topic-shorts/show', [
            'short' => [
                'id' => $short->id,
                'topic' => $short->topic,
                'title' => $short->title,
                'language' => $short->language,
                'style' => TopicShort::STYLES[$short->style] ?? $short->style,
                'image_style' => $short->image_style,
                'target_seconds' => $short->target_seconds,
                'wiki' => $short->wiki,
                'status' => $short->status,
                'error' => $short->error,
                'use_research' => $short->use_research,
                'research' => $short->research,
                'research_notes' => $short->research_notes,
                'hooks' => $short->hooks ?? [],
                'hook_index' => $short->hook_index,
                'series_parts' => $short->series_parts,
                'part' => $short->part,
                'series' => $short->series ? [
                    'id' => $short->series->id,
                    'parts' => $short->series->shorts()->get(['id', 'part', 'title', 'status'])->map(fn (TopicShort $s) => ['id' => $s->id, 'part' => $s->part, 'title' => $s->title, 'status' => $s->status, 'focus' => $short->series->outline[$s->part - 1]['title'] ?? '']),
                ] : null,
                'missing_panels' => $missing,
                'cover_url' => Media::url($short->cover_path),
                'cover' => $short->cover,
                'publish' => $short->publish,
                'unsupported_count' => $scenes->filter(fn ($scene) => ($scene->fact_check['status'] ?? null) === 'unsupported')->count(),
                'caption' => $short->content['caption'] ?? '',
                'art_style' => $short->art_style,
                'characters' => collect($short->characters ?? [])->map(fn ($c) => ['name' => $c['name'], 'look' => $c['look'] ?? '', 'image_url' => Media::url($c['image_path'] ?? null)])->all(),
                'hashtags' => $short->content['hashtags'] ?? [],
                'video_status' => $short->video_status,
                'video_error' => $short->video_error,
                'video_url' => Media::url($short->video_path),
                'video_ms' => $short->video_ms,
                'estimated_ms' => $estimatedMs,
                'images_busy' => $scenes->contains(fn ($scene) => in_array($scene->image_status, ['searching', 'pending'], true)),
                'image_stats' => collect(['ready', 'failed', 'pending', 'searching'])->mapWithKeys(fn ($status) => [$status => $scenes->where('image_status', $status)->count()]),
                'all_images_ready' => $scenes->isNotEmpty() && $scenes->every(fn ($scene) => $scene->image_status === 'ready'),
                'scenes' => $scenes->map(fn (TopicShortScene $scene) => [
                    'id' => $scene->id,
                    'position' => $scene->position,
                    'narration' => $scene->narration,
                    'on_screen_text' => $scene->on_screen_text,
                    'image_query' => $scene->image_query,
                    'image_prompt' => $scene->image_prompt,
                    'characters' => $scene->characters ?? [],
                    'image_source' => $scene->image_source,
                    'image_status' => $scene->image_status,
                    'image_url' => Media::url($scene->image_path),
                    'image_source_url' => $scene->image_url,
                    'image_error' => $scene->image_error,
                    'muse_refusal' => $scene->muse_refusal,
                    'muse_fix_prompt' => $scene->muse_fix_prompt,
                    'candidates' => $scene->candidates ?? [],
                    'expression' => $scene->expression,
                    'fact_ids' => $scene->fact_ids ?? [],
                    'fact_check' => $scene->fact_check,
                    'mood' => $scene->mood,
                    'manga_panels' => collect($scene->manga_panels ?? [])->map(fn ($id) => $mangaPanels->get($id))->filter()->map(fn (MangaPanel $panel) => MangaPanelController::present($panel))->values(),
                    'voice_url' => Media::url($scene->voice_path),
                    'voice_ms' => $scene->voice_ms,
                    'panels' => collect(MangaPage::resolve($scene, $library))->map(fn ($item) => [
                        ...$item['panel'],
                        'name' => DragonBall::CHARACTERS[$item['panel']['character']][0] ?? $item['panel']['character'],
                        'label' => DragonBall::pose($item['panel']['character'], $item['panel']['pose'])[0] ?? $item['panel']['pose'],
                        'url' => Media::url($item['portrait']?->image_path),
                        'exact' => $item['exact'],
                    ])->all(),
                    // Ảnh thư viện nhân vật Ngọc Rồng của các nhân vật trong cảnh (bấm để dùng làm ảnh cảnh).
                    'library' => collect($scene->characters ?? [])->map(fn ($name) => ($key = DragonBall::keyFor($name)) ? CharacterPortrait::best($short->user_id, $key, $scene->expression) : null)
                        ->filter()->unique('id')->map(fn (CharacterPortrait $p) => ['id' => $p->id, 'name' => DragonBall::CHARACTERS[$p->character][0], 'expression' => $p->expression, 'url' => Media::url($p->image_path)])->values(),
                ]),
            ],
            'music' => $music = AudioTrack::where('user_id', $request->user()->id)->latest()->get(['id', 'name']),
            'builtin_music' => $builtin = BuiltinMusic::all(),
            // Kiểu dựng và tên kênh lần trước (đỡ gõ lại mỗi video).
            'render_defaults' => [
                'style' => $short->video_options['style'] ?? 'fast',
                'watermark' => $short->video_options['watermark']
                    ?? TopicShort::where('user_id', $short->user_id)->whereNotNull('video_options')->latest('updated_at')->get(['video_options'])->pluck('video_options.watermark')->filter()->first() ?? '',
            ],
            'music_suggestion' => ShortVideo::suggestMusic($scenes, $music, $builtin),
            'image_styles' => TopicShort::IMAGE_STYLES,
            'prices' => ['image' => CreditPricing::settings()['steps']['image'], 'render' => RenderTopicShortJob::price($estimatedMs)],
            'max_attempts' => CharacterLibraryController::MAX_ATTEMPTS,
            // Tên tiếng Anh trong lời kể và cách giọng Việt sẽ đọc (chỉ cho short tiếng Việt).
            'pronunciations' => $short->language === 'en' ? [] : Pronounce::found($scenes->pluck('narration')->implode("\n"), $short->user_id),
        ]);
    }

    /** Lưu chỉnh sửa tiêu đề, caption và lời kể / chữ / từ khoá / prompt từng cảnh. */
    public function update(Request $request, TopicShort $short): RedirectResponse
    {
        $this->authorize('update', $short);
        $ids = $short->scenes()->pluck('id')->all();
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'caption' => ['nullable', 'string', 'max:2000'],
            'scenes' => ['required', 'array'],
            'scenes.*.id' => ['required', 'integer', Rule::in($ids)],
            'scenes.*.narration' => ['required', 'string', 'max:1000'],
            'scenes.*.on_screen_text' => ['nullable', 'string', 'max:120'],
            'scenes.*.image_query' => ['nullable', 'string', 'max:200'],
            'scenes.*.image_prompt' => ['nullable', 'string', 'max:2000'],
            'scenes.*.characters' => ['nullable', 'array'],
            'scenes.*.characters.*' => ['string', Rule::in(array_column($short->characters ?? [], 'name'))],
            'art_style' => ['nullable', 'string', 'max:300'],
            'characters' => ['nullable', 'array'],
            'characters.*.look' => ['nullable', 'string', 'max:600'],
        ], [], ['scenes.*.narration' => 'lời kể']);

        $characters = $short->characters ?? [];
        foreach ($validated['characters'] ?? [] as $i => $data) {
            if (isset($characters[$i])) {
                $characters[$i]['look'] = $data['look'] ?? '';
            }
        }
        $short->update([
            'title' => $validated['title'] ?? '',
            'content' => [...($short->content ?? []), 'caption' => $validated['caption'] ?? ''],
            'art_style' => $validated['art_style'] ?? $short->art_style,
            'characters' => $characters,
        ]);
        $narrations = $short->scenes()->pluck('narration', 'id');
        foreach ($validated['scenes'] as $data) {
            $short->scenes()->whereKey($data['id'])->update([
                // Sửa lời kể thì kết quả kiểm chứng cũ không còn đúng.
                ...$short->use_research && $narrations[$data['id']] !== $data['narration'] ? ['fact_check' => json_encode(TopicShortScene::UNCHECKED)] : [],
                'narration' => $data['narration'],
                'on_screen_text' => $data['on_screen_text'] ?? '',
                'image_query' => $data['image_query'] ?? '',
                'image_prompt' => $data['image_prompt'] ?? '',
                ...array_key_exists('characters', $data) ? ['characters' => array_values($data['characters'] ?? [])] : [],
            ]);
        }

        return back()->with('status', 'Đã lưu lời kể.');
    }

    /** Tìm lại tư liệu (có thể kèm ghi chú mới), xoá bản tư liệu cũ. */
    public function research(Request $request, TopicShort $short): RedirectResponse
    {
        $this->authorize('update', $short);
        $validated = $request->validate(['research_notes' => ['nullable', 'string', 'max:2000']]);
        if (in_array($short->status, ['researching', 'writing'], true) || $short->video_status === 'rendering') {
            return back()->with('status', 'Đang chạy, chờ xong rồi tìm lại.');
        }
        $short->update([
            'use_research' => true,
            ...array_key_exists('research_notes', $validated) ? ['research_notes' => $validated['research_notes']] : [],
            'research' => null,
            'status' => 'researching',
            'error' => null,
        ]);
        ResearchTopicJob::dispatch($short);

        return back()->with('status', 'Claude đang tìm lại tư liệu...');
    }

    /** Duyệt tư liệu: giữ / bỏ / sửa từng chi tiết, lưu vào kho tư liệu rồi cho Claude viết kịch bản (series thì lên dàn ý các phần trước). */
    public function approveResearch(Request $request, TopicShort $short): RedirectResponse
    {
        $this->authorize('update', $short);
        $ids = array_column($short->research['facts'] ?? [], 'id');
        $validated = $request->validate([
            'facts' => ['required', 'array'],
            'facts.*.id' => ['required', 'string', Rule::in($ids)],
            'facts.*.enabled' => ['required', 'boolean'],
            'facts.*.text' => ['required', 'string', 'max:600'],
            'research_notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['facts.*.text' => 'nội dung tư liệu']);
        if (! in_array($short->status, ['research_ready', 'ready', 'failed'], true) || $short->research === null) {
            return back()->with('status', 'Chưa có tư liệu để duyệt.');
        }
        if (collect($validated['facts'])->where('enabled', true)->count() < 3) {
            return back()->withErrors(['facts' => 'Giữ ít nhất 3 chi tiết tư liệu.']);
        }

        $edits = collect($validated['facts'])->keyBy('id');
        $research = $short->research;
        foreach ($research['facts'] as &$fact) {
            if ($edit = $edits->get($fact['id'])) {
                $fact['enabled'] = (bool) $edit['enabled'];
                if (trim($edit['text']) !== $fact['text']) {
                    // Sửa nội dung thì là chi tiết mới của kho.
                    $fact['text'] = trim($edit['text']);
                    $fact['lore_id'] = null;
                }
            }
        }
        unset($fact);
        $short->update(['research' => $research, 'research_notes' => $validated['research_notes'] ?? null]);
        $saved = TopicResearcher::remember($short);

        if ($short->series_parts > 1 && $short->topic_series_id === null) {
            $short->update(['status' => 'writing', 'error' => null]);
            PlanTopicSeriesJob::dispatch($short);

            return back()->with('status', "Đã lưu {$saved} chi tiết vào kho. Claude đang chia {$short->series_parts} phần rồi viết kịch bản...");
        }
        $short->update(['status' => 'writing', 'error' => null]);
        WriteTopicShortJob::dispatch($short);

        return back()->with('status', "Đã lưu {$saved} chi tiết vào kho tư liệu. Claude đang viết kịch bản...");
    }

    /** Đổi câu hook của cảnh 1 (3 câu Claude viết sẵn). */
    public function hook(Request $request, TopicShort $short): RedirectResponse
    {
        $this->authorize('update', $short);
        $hooks = $short->hooks ?? [];
        $validated = $request->validate(['index' => ['required', 'integer', 'min:0', 'max:'.max(count($hooks) - 1, 0)]]);
        abort_if($hooks === [], 404);
        $short->scenes()->where('position', 1)->update(['narration' => $hooks[$validated['index']]['text'], 'fact_check' => $short->use_research ? json_encode(TopicShortScene::UNCHECKED) : null]);
        $short->update(['hook_index' => $validated['index']]);

        return back()->with('status', 'Đã đổi câu mở đầu.');
    }

    /** Kiểm chứng lại lời kể với tư liệu (sau khi sửa lời kể). */
    public function factCheck(TopicShort $short): RedirectResponse
    {
        $this->authorize('update', $short);
        if (! $short->use_research || $short->status !== 'ready') {
            return back()->with('status', 'Short này không có tư liệu để kiểm chứng.');
        }
        $short->scenes()->update(['fact_check' => null]);
        CheckTopicShortFactsJob::dispatch($short);

        return back()->with('status', 'Claude đang kiểm chứng lời kể...');
    }

    /** Claude viết lại từ đầu (xoá các cảnh và ảnh cũ). */
    public function rewrite(TopicShort $short): RedirectResponse
    {
        $this->authorize('update', $short);
        if (in_array($short->status, ['writing', 'researching'], true) || $short->video_status === 'rendering') {
            return back()->with('status', 'Đang chạy, chờ xong rồi viết lại.');
        }
        $short->scenes()->get()->each(fn (TopicShortScene $scene) => Media::delete($scene->image_path));
        $short->update(['status' => 'writing', 'error' => null]);
        WriteTopicShortJob::dispatch($short);

        return back()->with('status', 'Claude đang viết lại...');
    }

    /** Tìm lại ảnh wiki (có thể kèm từ khoá mới). */
    public function search(Request $request, TopicShortScene $scene): RedirectResponse
    {
        $this->authorize('update', $scene->topicShort);
        $validated = $request->validate(['query' => ['nullable', 'string', 'max:200']]);
        if ($this->busy($scene)) {
            return back()->with('status', 'Ảnh cảnh này đang được tạo.');
        }
        $scene->update([...filled($validated['query'] ?? null) ? ['image_query' => $validated['query']] : [], 'image_status' => 'searching', 'image_error' => null]);
        FindTopicSceneImageJob::dispatch($scene);

        return back()->with('status', "Đang tìm ảnh cảnh {$scene->position}...");
    }

    /** Chọn một ảnh khác trong kết quả tìm wiki. */
    public function pick(Request $request, TopicShortScene $scene): RedirectResponse
    {
        $this->authorize('update', $scene->topicShort);
        $validated = $request->validate(['url' => ['required', 'string', Rule::in(collect($scene->candidates ?? [])->pluck('url')->all())]], [], ['url' => 'ảnh']);
        if ($this->busy($scene)) {
            return back()->with('status', 'Ảnh cảnh này đang được tạo.');
        }
        try {
            FindTopicSceneImageJob::store($scene, $validated['url'], 'web');
        } catch (RuntimeException $e) {
            return back()->withErrors(["scene_{$scene->id}" => $e->getMessage()]);
        }

        return back()->with('status', "Đã đổi ảnh cảnh {$scene->position}.");
    }

    /** Dùng ảnh thư viện nhân vật làm ảnh cảnh. */
    public function library(Request $request, TopicShortScene $scene): RedirectResponse
    {
        $this->authorize('update', $scene->topicShort);
        $validated = $request->validate(['portrait_id' => ['required', 'integer']]);
        $portrait = $request->user()->characterPortraits()->where('status', 'ready')->findOrFail($validated['portrait_id']);
        if ($this->busy($scene)) {
            return back()->with('status', 'Ảnh cảnh này đang được tạo.');
        }
        FindTopicSceneImageJob::useLibrary($scene, $portrait);

        return back()->with('status', "Đã dùng ảnh nhân vật cho cảnh {$scene->position}.");
    }

    /** Ghép lại trang manga của cảnh từ thư viện nhân vật. */
    public function compose(TopicShortScene $scene): RedirectResponse
    {
        $this->authorize('update', $scene->topicShort);
        if (($scene->panels ?? []) === [] || $this->busy($scene)) {
            return back()->with('status', 'Cảnh này không có khung manga hoặc ảnh đang được tạo.');
        }

        return back()->with('status', MangaPage::compose($scene)
            ? "Đã ghép trang manga cảnh {$scene->position}."
            : "Chưa có ảnh thư viện nào cho các nhân vật cảnh {$scene->position}, bấm Vẽ khung còn thiếu.");
    }

    /** Ghép lại trang manga mọi cảnh có khung (trừ cảnh bạn tự tải ảnh lên). */
    public function composeAll(TopicShort $short): RedirectResponse
    {
        $this->authorize('update', $short);
        $done = $short->scenes()->whereNotNull('panels')->get()
            ->reject(fn (TopicShortScene $scene) => $scene->image_source === 'upload' || ($scene->panels ?? []) === [] || $this->busy($scene))
            ->filter(fn (TopicShortScene $scene) => MangaPage::compose($scene))->count();

        return back()->with('status', $done > 0 ? "Đã ghép {$done} trang manga." : 'Không có trang nào ghép được.');
    }

    /** Muse vẽ vào thư viện các tư thế cảnh cần mà chưa có; vẽ xong trang manga tự ghép lại. */
    public function drawMissing(Request $request, TopicShort $short): RedirectResponse
    {
        $this->authorize('update', $short);
        $user = $request->user();
        $existing = $user->characterPortraits()->get()->keyBy(fn (CharacterPortrait $p) => "{$p->character}:{$p->expression}");
        $targets = collect(MangaPage::missing($short->scenes()->get(), $user->id))
            ->reject(fn ($p) => $p['status'] === 'pending' || ($p['status'] === 'failed' && $existing->get("{$p['character']}:{$p['pose']}")->attempts >= CharacterLibraryController::MAX_ATTEMPTS))
            ->map(fn ($p) => [$p['character'], $p['pose']])->values()->all();
        // Nhân vật chưa có mặt thường: vẽ E1 trước để các khung khác bám theo cho giống.
        foreach (array_unique(array_column($targets, 0)) as $key) {
            if (! in_array($existing->get("{$key}:E1")?->status, ['ready', 'pending'], true) && ! in_array([$key, 'E1'], $targets, true)) {
                $targets[] = [$key, 'E1'];
            }
        }
        if ($targets === []) {
            return back()->with('status', 'Không có khung nào cần vẽ (ô bị Muse từ chối 2 lần cần tải ảnh lên ở trang Nhân vật Ngọc Rồng).');
        }
        if ($message = Credits::shortfall($user, count($targets) * CreditPricing::settings()['steps']['image'], 'vẽ ảnh')) {
            return back()->with('status', $message);
        }
        GenerateCharacterPortraitJob::drawAll($user, $targets);

        return back()->with('status', 'Muse đang vẽ '.count($targets).' khung vào thư viện, xong sẽ tự ghép trang.');
    }

    /** Muse vẽ ảnh mới cho cảnh (có thể kèm prompt mới). */
    public function muse(Request $request, TopicShortScene $scene): RedirectResponse
    {
        $short = $scene->topicShort;
        $this->authorize('update', $short);
        $validated = $request->validate(['prompt' => ['nullable', 'string', 'max:2000'], 'fix' => ['boolean']]);
        if ($this->busy($scene)) {
            return back()->with('status', 'Ảnh cảnh này đang được tạo.');
        }
        if ($message = Credits::shortfall($request->user(), CreditPricing::settings()['steps']['image'], 'vẽ ảnh')) {
            return back()->with('status', $message);
        }
        GenerateTopicSceneImageJob::resetCounters($scene);
        // Nút "Tạo lại ảnh với prompt này" (prompt sửa theo góp ý của Muse).
        ($validated['fix'] ?? false) && filled($validated['prompt'] ?? null) && GenerateTopicSceneImageJob::markAsFix($scene);
        $scene->update([...filled($validated['prompt'] ?? null) ? ['image_prompt' => $validated['prompt']] : [], 'image_status' => 'pending', 'request_id' => null, 'image_error' => null, 'muse_refusal' => null, 'muse_fix_prompt' => null]);
        // Không gõ prompt riêng: Claude viết prompt khớp lời kể của cảnh trước khi Muse vẽ.
        blank($validated['prompt'] ?? null) && GenerateTopicSceneImageJob::markForFreshPrompt($scene);
        GenerateTopicSceneImageJob::dispatch($scene);

        return back()->with('status', "Claude viết prompt theo lời kể, rồi Muse vẽ ảnh cảnh {$scene->position}...");
    }

    public function upload(Request $request, TopicShortScene $scene): RedirectResponse
    {
        $this->authorize('update', $scene->topicShort);
        $request->validate(['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:15360']], [], ['image' => 'ảnh']);
        if ($this->busy($scene)) {
            return back()->with('status', 'Ảnh cảnh này đang được tạo.');
        }
        $old = $scene->image_path;
        $scene->update([
            'manga_panels' => null,
            'image_source' => 'upload',
            'image_status' => 'ready',
            'image_path' => Media::store($request->file('image'), "topic-shorts/{$scene->topic_short_id}"),
            'image_url' => null,
            'image_error' => null,
        ]);
        Media::delete($old);

        return back()->with('status', "Đã tải ảnh cảnh {$scene->position}.");
    }

    /** Claude tìm ảnh thật trên mạng cho mọi cảnh (hoặc các cảnh còn thiếu ảnh). */
    public function findImages(Request $request, TopicShort $short): RedirectResponse
    {
        $this->authorize('update', $short);
        $scenes = $short->scenes()->whereNotIn('image_status', ['searching', 'pending'])
            ->when($request->boolean('missing'), fn ($q) => $q->where(fn ($q) => $q->whereNull('manga_panels')->orWhere('manga_panels', '[]')))->get();
        if ($short->status !== 'ready' || $scenes->isEmpty()) {
            return back()->with('status', 'Không có cảnh nào cần tìm ảnh.');
        }

        return $this->startFinding($short, $scenes->pluck('id')->all());
    }

    public function findSceneImages(TopicShortScene $scene): RedirectResponse
    {
        $this->authorize('update', $scene->topicShort);
        if ($this->busy($scene)) {
            return back()->with('status', 'Ảnh cảnh này đang được tạo.');
        }

        return $this->startFinding($scene->topicShort, [$scene->id]);
    }

    /** @param list<int> $ids */
    private function startFinding(TopicShort $short, array $ids): RedirectResponse
    {
        TopicShortScene::whereIn('id', $ids)->update(['image_status' => 'searching', 'image_error' => null]);
        FindSceneImagesJob::dispatch($short, $ids);

        return back()->with('status', 'Claude đang tìm ảnh trên mạng (vài phút), tìm xong ảnh tự gắn vào cảnh.');
    }

    /** Ảnh bạn tự tìm: thêm vào cảnh (mỗi ảnh một cảnh quay, tối đa 3) và lưu vào Kho khung manga. */
    public function addImages(Request $request, TopicShortScene $scene): RedirectResponse
    {
        $this->authorize('update', $scene->topicShort);
        $request->validate(['images' => ['required', 'array', 'max:'.ShortWriter::MAX_PANELS], 'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:15360']], [], ['images' => 'ảnh', 'images.*' => 'ảnh']);
        $ids = [];
        foreach ($request->file('images') as $file) {
            $path = Media::store($file, "manga/{$request->user()->id}/uploads");
            $size = @getimagesize($file->getRealPath()) ?: [0, 0];
            $scan = $request->user()->mangaScans()->create(['chapter' => 'Tự tải lên', 'name' => mb_substr($file->getClientOriginalName(), 0, 250), 'image_path' => $path, 'status' => 'processing']);
            $ids[] = $scan->panels()->create(['user_id' => $request->user()->id, 'position' => 1, 'image_path' => $path, 'width' => $size[0], 'height' => $size[1], 'characters' => [], 'useful' => true])->id;
            // Claude xem ảnh để ghi nhân vật / mô tả (dùng lại cho video sau).
            ProcessMangaScanJob::dispatch($scan);
        }
        $scene->update(['manga_panels' => array_slice([...($scene->manga_panels ?? []), ...$ids], -ShortWriter::MAX_PANELS)]);
        MangaPage::composeOrExplain($scene->fresh());

        return back()->with('status', 'Đã thêm '.count($ids)." ảnh vào cảnh {$scene->position}.");
    }

    /** Chọn khung manga thật cho cảnh (tối đa 3, theo thứ tự), ghép lại trang; bỏ hết thì quay về khung thư viện nhân vật. */
    public function manga(Request $request, TopicShortScene $scene): RedirectResponse
    {
        $this->authorize('update', $scene->topicShort);
        $validated = $request->validate(['ids' => ['present', 'array', 'max:'.ShortWriter::MAX_PANELS], 'ids.*' => ['integer']]);
        $ids = array_values(array_unique(array_map('intval', $validated['ids'])));
        $mine = $request->user()->mangaPanels()->whereIn('id', $ids)->pluck('id')->all();
        $scene->update(['manga_panels' => array_values(array_filter($ids, fn ($id) => in_array($id, $mine, true)))]);
        MangaPage::composeOrExplain($scene);

        return back()->with('status', "Đã ghép lại ảnh cảnh {$scene->position}.");
    }

    /** Giọng tự thu cho một cảnh: dựng video dùng file này thay giọng máy. */
    public function voice(Request $request, TopicShortScene $scene): RedirectResponse
    {
        $this->authorize('update', $scene->topicShort);
        $request->validate(['voice' => ['required', 'file', 'mimes:mp3,m4a,aac,wav,ogg,mpga', 'max:20480']], [], ['voice' => 'file giọng']);
        $file = $request->file('voice');
        $ms = (int) round(EpisodeRenderer::duration($file->getRealPath()) * 1000);
        if ($ms <= 0) {
            return back()->withErrors(['voice' => 'Không đọc được file âm thanh này.']);
        }
        $old = $scene->voice_path;
        $scene->update(['voice_path' => Media::store($file, "topic-shorts/{$scene->topic_short_id}/voices"), 'voice_ms' => $ms]);
        Media::delete($old);

        return back()->with('status', "Đã tải giọng cảnh {$scene->position}. Dựng lại video để dùng giọng này.");
    }

    public function voiceDestroy(TopicShortScene $scene): RedirectResponse
    {
        $this->authorize('update', $scene->topicShort);
        Media::delete($scene->voice_path);
        $scene->update(['voice_path' => null, 'voice_ms' => null]);

        return back()->with('status', "Cảnh {$scene->position} quay lại giọng máy.");
    }

    /** Ảnh mẫu của một nhân vật: Muse nhận kèm để vẽ đúng mặt, tóc, trang phục. */
    public function characterImage(Request $request, TopicShort $short, int $index): RedirectResponse
    {
        $this->authorize('update', $short);
        $characters = $short->characters ?? [];
        abort_unless(isset($characters[$index]), 404);
        $request->validate(['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:15360']], [], ['image' => 'ảnh']);

        $old = $characters[$index]['image_path'] ?? null;
        $characters[$index]['image_path'] = Media::store($request->file('image'), "topic-shorts/{$short->id}/characters");
        $short->update(['characters' => $characters]);
        Media::delete($old);

        return back()->with('status', "Đã lưu ảnh mẫu {$characters[$index]['name']}.");
    }

    /** Tạo lại mọi ảnh cảnh bị lỗi, dùng thẳng prompt đã bỏ tên nhân vật / phim (AI hay từ chối tên có bản quyền). */
    public function retryFailed(Request $request, TopicShort $short): RedirectResponse
    {
        $this->authorize('update', $short);
        $scenes = $short->scenes()->where(fn ($q) => $q->where('image_status', 'failed')->orWhereNotNull('muse_fix_prompt'))->get();
        if ($scenes->isEmpty()) {
            return back()->with('status', 'Không có ảnh nào bị lỗi.');
        }
        if ($message = Credits::shortfall($request->user(), $scenes->count() * CreditPricing::settings()['steps']['image'], 'vẽ ảnh')) {
            return back()->with('status', $message);
        }
        foreach ($scenes as $scene) {
            // Lời từ chối của AI nằm sau "AI trả lời:" trong lỗi; Claude viết lại prompt theo đó.
            GenerateTopicSceneImageJob::resetCounters($scene);
            // Đã có prompt Claude sửa theo góp ý của Muse thì vẽ luôn bằng prompt đó.
            filled($scene->muse_fix_prompt)
                ? GenerateTopicSceneImageJob::markAsFix($scene)
                : GenerateTopicSceneImageJob::markForRewrite($scene, Str::after((string) $scene->image_error, 'AI trả lời:'));
            $scene->update([...filled($scene->muse_fix_prompt) ? ['image_prompt' => $scene->muse_fix_prompt] : [], 'image_status' => 'pending', 'request_id' => null, 'image_error' => null, 'muse_refusal' => null, 'muse_fix_prompt' => null]);
            GenerateTopicSceneImageJob::dispatch($scene);
        }

        return back()->with('status', "Claude đang viết lại prompt và tạo lại {$scenes->count()} ảnh lỗi...");
    }

    /** Muse vẽ lại mọi cảnh (trừ ảnh bạn tự tải lên và cảnh đang tạo ảnh). */
    public function museAll(Request $request, TopicShort $short): RedirectResponse
    {
        $this->authorize('update', $short);
        $validated = $request->validate(['image_style' => ['nullable', Rule::in(array_keys(TopicShort::IMAGE_STYLES))]]);
        if (filled($validated['image_style'] ?? null)) {
            $short->update(['image_style' => $validated['image_style']]);
        }
        $scenes = $short->scenes()->get()->reject(fn (TopicShortScene $scene) => $scene->image_source === 'upload' || $this->busy($scene));
        if ($scenes->isEmpty()) {
            return back()->with('status', 'Không có cảnh nào cần vẽ lại.');
        }
        if ($message = Credits::shortfall($request->user(), $scenes->count() * CreditPricing::settings()['steps']['image'], 'vẽ ảnh')) {
            return back()->with('status', $message);
        }
        foreach ($scenes as $scene) {
            GenerateTopicSceneImageJob::resetCounters($scene);
            $scene->update(['image_status' => 'pending', 'request_id' => null, 'image_error' => null]);
            GenerateTopicSceneImageJob::markForFreshPrompt($scene);
            GenerateTopicSceneImageJob::dispatch($scene);
        }

        return back()->with('status', "Muse đang vẽ lại {$scenes->count()} cảnh...");
    }

    public function render(Request $request, TopicShort $short): RedirectResponse
    {
        $this->authorize('update', $short);
        $validated = $request->validate([
            'music_track_id' => ['nullable', 'integer', Rule::exists('audio_tracks', 'id')->where('user_id', $request->user()->id)],
            'music_file' => ['nullable', 'string', Rule::in(array_column(BuiltinMusic::all(), 'file'))],
            'music_volume' => ['nullable', 'integer', 'min:0', 'max:40'],
            'sfx' => ['boolean'],
            'style' => ['nullable', Rule::in(['fast', 'page'])],
            'watermark' => ['nullable', 'string', 'max:30'],
        ]);
        if ($short->status !== 'ready' || ! $short->allImagesReady()) {
            return back()->with('status', 'Mọi cảnh cần có ảnh trước khi dựng video.');
        }
        if ($short->video_status === 'rendering') {
            return back()->with('status', 'Video đang được dựng.');
        }
        if ($message = Credits::shortfall($request->user(), RenderTopicShortJob::price($short->target_seconds * 1000), 'dựng video')) {
            return back()->with('status', $message);
        }
        $run = Str::lower(Str::random(8));
        $short->update(['video_status' => 'rendering', 'video_error' => null, 'video_options' => [...$validated, 'run' => $run]]);
        RenderTopicShortJob::dispatch($short, $run);

        return back()->with('status', 'Đang đọc giọng và dựng video (khoảng 1–2 phút)...');
    }

    /** Claude viết (lại) tiêu đề, mô tả, hashtag và chữ ảnh bìa. */
    public function publish(TopicShort $short): RedirectResponse
    {
        $this->authorize('update', $short);
        if ($short->status !== 'ready' || $short->scenes()->doesntExist()) {
            return back()->with('status', 'Cần có lời kể trước khi viết tiêu đề.');
        }
        if (($short->publish['status'] ?? null) === 'writing' && $short->updated_at?->gt(now()->subMinutes(10))) {
            return back()->with('status', 'Claude đang viết tiêu đề.');
        }
        $short->update(['publish' => [...($short->publish ?? []), 'status' => 'writing', 'error' => null]]);
        WriteTopicShortPublishJob::dispatch($short);

        return back()->with('status', 'Claude đang viết tiêu đề và mô tả...');
    }

    /** Lưu tiêu đề / mô tả / hashtag bạn sửa. */
    public function savePublish(Request $request, TopicShort $short): RedirectResponse
    {
        $this->authorize('update', $short);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:3000'],
            'hashtags' => ['nullable', 'string', 'max:500'],
        ], [], ['title' => 'tiêu đề', 'description' => 'mô tả']);
        $tags = collect(preg_split('/[\s,]+/u', (string) ($validated['hashtags'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))->map(fn ($t) => '#'.ltrim($t, '#'))->unique()->values()->all();
        $short->update(['publish' => [...($short->publish ?? []), 'status' => 'ready', 'title' => $validated['title'], 'description' => $validated['description'] ?? '', 'hashtags' => $tags]]);

        return back()->with('status', 'Đã lưu tiêu đề và mô tả.');
    }

    /** Ghép (lại) ảnh bìa từ ảnh một cảnh + chữ lớn. */
    public function cover(Request $request, TopicShort $short): RedirectResponse
    {
        $this->authorize('update', $short);
        $validated = $request->validate([
            'scene_id' => ['required', 'integer', Rule::in($short->scenes()->pluck('id')->all())],
            'text' => ['nullable', 'string', 'max:80'],
        ], [], ['text' => 'chữ ảnh bìa']);
        try {
            CoverImage::make($short, $short->scenes()->findOrFail($validated['scene_id']), (string) ($validated['text'] ?? ''));
        } catch (RuntimeException $e) {
            return back()->withErrors(['cover' => $e->getMessage()]);
        }

        return back()->with('status', 'Đã tạo ảnh bìa.');
    }

    public function coverDownload(TopicShort $short): StreamedResponse|RedirectResponse
    {
        $this->authorize('view', $short);
        if ($short->cover_path === null) {
            return back()->with('status', 'Chưa có ảnh bìa.');
        }

        return Media::disk()->download($short->cover_path, 'bia-'.Str::slug($short->publish['title'] ?? $short->title ?: $short->topic).'.jpg');
    }

    public function download(TopicShort $short): StreamedResponse|RedirectResponse
    {
        $this->authorize('view', $short);
        if ($short->video_path === null) {
            return back()->with('status', 'Chưa có video.');
        }

        return Media::disk()->download($short->video_path, 'short-'.Str::slug($short->title ?: $short->topic).'.mp4');
    }

    public function destroy(TopicShort $short): RedirectResponse
    {
        $this->authorize('delete', $short);
        $short->scenes()->get()->each(fn (TopicShortScene $scene) => Media::delete($scene->image_path));
        Media::delete($short->video_path);
        Media::delete($short->cover_path);
        collect($short->characters ?? [])->each(fn ($c) => Media::delete($c['image_path'] ?? null));
        $short->delete();

        return to_route($short->use_research ? 'story-videos.index' : 'topic-shorts.index')->with('status', 'Đã xoá short.');
    }

    private function busy(TopicShortScene $scene): bool
    {
        return in_array($scene->image_status, ['searching', 'pending'], true) && $scene->updated_at?->gt(now()->subMinutes(30));
    }
}
