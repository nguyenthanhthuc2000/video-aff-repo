<?php

namespace App\Http\Controllers;

use App\Jobs\FindTopicSceneImageJob;
use App\Jobs\GenerateTopicSceneImageJob;
use App\Jobs\RenderTopicShortJob;
use App\Jobs\WriteTopicShortJob;
use App\Models\AudioTrack;
use App\Models\CharacterPortrait;
use App\Models\TopicShort;
use App\Models\TopicShortScene;
use App\Services\TopicShort\ShortWriter;
use App\Support\CreditPricing;
use App\Support\Credits;
use App\Support\DragonBall;
use App\Support\Media;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
        $shorts = $request->user()->topicShorts()->latest()->withCount('scenes')->get();

        return Inertia::render('topic-shorts/index', [
            'shorts' => $shorts->map(fn (TopicShort $short) => [
                'id' => $short->id,
                'topic' => $short->topic,
                'title' => $short->title,
                'language' => $short->language,
                'status' => $short->status,
                'video_status' => $short->video_status,
                'scenes_count' => $short->scenes_count,
                'created_at' => $short->created_at,
            ]),
            'languages' => TopicShort::LANGUAGES,
            'styles' => TopicShort::STYLES,
            'image_styles' => TopicShort::IMAGE_STYLES,
            'wikis' => collect(config('videoaff.topic_shorts.wikis'))->map(fn ($wiki) => $wiki['label']),
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
        ], [], ['topic' => 'chủ đề', 'target_seconds' => 'độ dài']);

        $short = $request->user()->topicShorts()->create([...$validated, 'status' => 'writing']);
        WriteTopicShortJob::dispatch($short);

        return to_route('topic-shorts.show', $short)->with('status', 'Claude đang viết lời kể...');
    }

    public function show(Request $request, TopicShort $short): Response
    {
        $this->authorize('view', $short);
        $scenes = $short->scenes()->get();
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
                    'candidates' => $scene->candidates ?? [],
                    'expression' => $scene->expression,
                    // Ảnh thư viện nhân vật Ngọc Rồng của các nhân vật trong cảnh (bấm để dùng làm ảnh cảnh).
                    'library' => collect($scene->characters ?? [])->map(fn ($name) => ($key = DragonBall::keyFor($name)) ? CharacterPortrait::best($short->user_id, $key, $scene->expression) : null)
                        ->filter()->unique('id')->map(fn (CharacterPortrait $p) => ['id' => $p->id, 'name' => DragonBall::CHARACTERS[$p->character][0], 'expression' => $p->expression, 'url' => Media::url($p->image_path)])->values(),
                ]),
            ],
            'music' => AudioTrack::where('user_id', $request->user()->id)->latest()->get(['id', 'name']),
            'image_styles' => TopicShort::IMAGE_STYLES,
            'prices' => ['image' => CreditPricing::settings()['steps']['image'], 'render' => RenderTopicShortJob::price($estimatedMs)],
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
        foreach ($validated['scenes'] as $data) {
            $short->scenes()->whereKey($data['id'])->update([
                'narration' => $data['narration'],
                'on_screen_text' => $data['on_screen_text'] ?? '',
                'image_query' => $data['image_query'] ?? '',
                'image_prompt' => $data['image_prompt'] ?? '',
                ...array_key_exists('characters', $data) ? ['characters' => array_values($data['characters'] ?? [])] : [],
            ]);
        }

        return back()->with('status', 'Đã lưu lời kể.');
    }

    /** Claude viết lại từ đầu (xoá các cảnh và ảnh cũ). */
    public function rewrite(TopicShort $short): RedirectResponse
    {
        $this->authorize('update', $short);
        if ($short->status === 'writing' || $short->video_status === 'rendering') {
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

    /** Muse vẽ ảnh mới cho cảnh (có thể kèm prompt mới). */
    public function muse(Request $request, TopicShortScene $scene): RedirectResponse
    {
        $short = $scene->topicShort;
        $this->authorize('update', $short);
        $validated = $request->validate(['prompt' => ['nullable', 'string', 'max:2000']]);
        if ($this->busy($scene)) {
            return back()->with('status', 'Ảnh cảnh này đang được tạo.');
        }
        if ($message = Credits::shortfall($request->user(), CreditPricing::settings()['steps']['image'], 'vẽ ảnh')) {
            return back()->with('status', $message);
        }
        $scene->update([...filled($validated['prompt'] ?? null) ? ['image_prompt' => $validated['prompt']] : [], 'image_status' => 'pending', 'request_id' => null, 'image_error' => null]);
        GenerateTopicSceneImageJob::dispatch($scene);

        return back()->with('status', "Muse đang vẽ ảnh cảnh {$scene->position}...");
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
            'image_source' => 'upload',
            'image_status' => 'ready',
            'image_path' => Media::store($request->file('image'), "topic-shorts/{$scene->topic_short_id}"),
            'image_url' => null,
            'image_error' => null,
        ]);
        Media::delete($old);

        return back()->with('status', "Đã tải ảnh cảnh {$scene->position}.");
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
        $scenes = $short->scenes()->where('image_status', 'failed')->get();
        if ($scenes->isEmpty()) {
            return back()->with('status', 'Không có ảnh nào bị lỗi.');
        }
        if ($message = Credits::shortfall($request->user(), $scenes->count() * CreditPricing::settings()['steps']['image'], 'vẽ ảnh')) {
            return back()->with('status', $message);
        }
        foreach ($scenes as $scene) {
            // Lời từ chối của AI nằm sau "AI trả lời:" trong lỗi; Claude viết lại prompt theo đó.
            Cache::forget("muse-rejects:topic-scene:{$scene->id}");
            GenerateTopicSceneImageJob::markForRewrite($scene, Str::after((string) $scene->image_error, 'AI trả lời:'));
            $scene->update(['image_status' => 'pending', 'request_id' => null, 'image_error' => null]);
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
            $scene->update(['image_status' => 'pending', 'request_id' => null, 'image_error' => null]);
            GenerateTopicSceneImageJob::dispatch($scene);
        }

        return back()->with('status', "Muse đang vẽ lại {$scenes->count()} cảnh...");
    }

    public function render(Request $request, TopicShort $short): RedirectResponse
    {
        $this->authorize('update', $short);
        $validated = $request->validate([
            'music_track_id' => ['nullable', 'integer', Rule::exists('audio_tracks', 'id')->where('user_id', $request->user()->id)],
            'music_volume' => ['nullable', 'integer', 'min:0', 'max:40'],
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
        collect($short->characters ?? [])->each(fn ($c) => Media::delete($c['image_path'] ?? null));
        $short->delete();

        return to_route('topic-shorts.index')->with('status', 'Đã xoá short.');
    }

    private function busy(TopicShortScene $scene): bool
    {
        return in_array($scene->image_status, ['searching', 'pending'], true) && $scene->updated_at?->gt(now()->subMinutes(30));
    }
}
