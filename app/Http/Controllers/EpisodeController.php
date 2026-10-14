<?php

namespace App\Http\Controllers;

use App\Jobs\AnimateShotJob;
use App\Jobs\ComposeScoreJob;
use App\Jobs\GenerateShotImageJob;
use App\Jobs\GenerateThumbnailArtJob;
use App\Jobs\PlanEpisodeSoundsJob;
use App\Jobs\RenderEpisodeJob;
use App\Jobs\RewriteShotClipJob;
use App\Jobs\RewriteShotJob;
use App\Jobs\SuggestTitlesJob;
use App\Jobs\SynthesizeShotAudioJob;
use App\Jobs\WriteEpisodeJob;
use App\Jobs\WriteSequenceJob;
use App\Models\Episode;
use App\Models\EpisodeRender;
use App\Models\Series;
use App\Models\SeriesCharacter;
use App\Models\Shot;
use App\Services\Film\EpisodeDialogue;
use App\Services\Film\EpisodeRenderer;
use App\Services\Film\EpisodeTitler;
use App\Services\Film\EpisodeTranslator;
use App\Services\Film\EpisodeWriter;
use App\Services\Film\ShotAudio;
use App\Services\Film\ShotDub;
use App\Services\Film\ShotPrompt;
use App\Services\Film\ShotRewriter;
use App\Services\Film\SoundLibrary;
use App\Services\Film\Thumbnail;
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
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Trang một tập phim: thông tin tập, nhân vật xuất hiện, Claude viết tập thành shot, Muse vẽ khung hình từng shot. */
class EpisodeController extends Controller
{
    public function show(Series $series, Episode $episode): Response
    {
        $this->authorize('view', $series);
        $episodes = $series->episodes()->get(['id', 'number', 'title']);
        $index = $episodes->search(fn (Episode $e) => $e->id === $episode->id);
        $nav = fn (?Episode $e) => $e ? ['id' => $e->id, 'number' => $e->number, 'title' => $e->title] : null;
        $shots = $episode->shots()->with('location:id,name')->get()->each->setRelation('episode', $episode);
        $characterMap = $series->characters()->get()->keyBy('id');
        $outline = $episode->outline ?? [];
        $sequenceCount = count($outline['sequences'] ?? []) ?: max(2, (int) round($series->episode_seconds / EpisodeWriter::SEQUENCE_SECONDS));

        return Inertia::render('series/episode', [
            'series' => [
                'id' => $series->id,
                'title' => $series->title,
                'silent' => $series->silent(),
                'music_video' => $series->musicVideo(),
                'vertical' => $series->vertical(),
                'clip_budget' => $series->silent() ? $series->clipBudget() : null,
                'episode_seconds' => $series->episode_seconds,
                'episode_count' => $episodes->count(),
            ],
            'episode' => [
                'id' => $episode->id,
                'number' => $episode->number,
                'title' => $episode->title,
                'synopsis' => $episode->synopsis,
                'lesson' => $episode->lesson,
                'kind' => $episode->kind,
                'brief' => $episode->brief,
                'package' => $episode->package,
                'character_ids' => $episode->character_ids ?? [],
                'status' => $episode->status,
                'status_label' => Episode::STATUSES[$episode->status] ?? $episode->status,
                'voice_engine' => $episode->voice_engine,
                'titles' => [
                    'status' => $episode->title_status,
                    'error' => $episode->title_error,
                    'ideas' => $episode->title_ideas ?? [],
                ],
                'sounds' => [
                    'status' => $episode->sound_status,
                    'error' => $episode->sound_error,
                    'library_ready' => SoundLibrary::installed(),
                    'items' => self::soundItems($episode, $shots),
                ],
                'render' => [
                    'status' => $episode->render_status,
                    'progress' => $episode->render_progress,
                    'error' => $episode->render_error,
                    'url' => Media::url($episode->render_path),
                    'rendered_at' => $episode->rendered_at,
                    'options' => self::renderOptions($series, $episode),
                ],
                'score' => [
                    'status' => $episode->score_status,
                    'error' => $episode->score_error,
                    'cues' => self::scoreCues($episode, $shots),
                ],
                'renders' => $episode->renders()->get()->map(fn (EpisodeRender $render) => [
                    'id' => $render->id,
                    'thumbnail_url' => Media::url($render->thumbnail_path),
                    'thumbnail_shot_id' => $render->thumbnail_options['shot_id'] ?? null,
                    'thumbnail_text' => $render->thumbnail_options['text'] ?? null,
                    'thumbnail_art' => (bool) ($render->thumbnail_options['art'] ?? false),
                    'art_url' => Media::url($render->art_path),
                    'art_status' => $render->art_status,
                    'art_error' => $render->art_error,
                    'lang' => $render->lang,
                    'label' => $render->label(),
                    'vertical' => $render->vertical(),
                    'url' => Media::url($render->path),
                    'has_srt' => $render->srt_path !== null && ! $render->silent(),
                    'duration_ms' => $render->duration_ms,
                    'created_at' => $render->created_at,
                ])->values(),
                'render_vi' => [
                    'status' => $episode->vi_render_status,
                    'progress' => $episode->vi_render_progress,
                    'error' => $episode->vi_render_error,
                    'url' => Media::url($episode->vi_render_path),
                    'rendered_at' => $episode->vi_rendered_at,
                    'stage' => $episode->vi_render_status === 'rendering' ? RenderEpisodeJob::stage($episode) : null,
                    'voice' => $episode->vi_render_options['vi_voice'] ?? 'plain',
                    'vertical' => str_starts_with((string) ($episode->vi_render_options['aspect'] ?? ''), '9:16'),
                    'needs_translation' => ! EpisodeTranslator::isCurrent($episode),
                    'title' => $episode->dub_vi['title'] ?? null,
                ],
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
                    'camera_move' => $shot->camera_move,
                    'action' => $shot->action,
                    'motion' => $shot->motion,
                    'sound_cue' => $shot->sound_cue,
                    'song_ms' => $shot->song_ms,
                    'lyric' => $shot->lyric,
                    'lip_sync' => $shot->lip_sync,
                    'animate' => $shot->animate,
                    'character_ids' => $shot->character_ids ?? [],
                    'location' => $shot->location?->name,
                    'dialogue' => $shot->dialogue ?? [],
                    'duration_ms' => $shot->duration_ms,
                    'continues_previous' => $shot->continues_previous,
                    'image_url' => Media::url($shot->image_path),
                    'image_status' => $shot->image_status,
                    'error' => $shot->error,
                    'rejected' => ShotRewriter::rejection($shot) !== null,
                    'clip_rejected' => ShotRewriter::clipRejection($shot) !== null,
                    'audio_url' => Media::url($shot->audio_path),
                    // Lời / giọng đã đổi sau khi đọc: tiếng (và video) là bản cũ.
                    'audio_current' => ShotAudio::isCurrent($shot, $characterMap),
                    // Đang chờ / đang đọc lời (nút "Đọc lời thoại"); làm video thì đọc trong lúc làm clip.
                    'audio_pending' => ShotAudio::isPending($shot),
                    'audio_error' => ShotAudio::error($shot),
                    'has_dialogue' => ShotAudio::key($shot, $characterMap) !== null,
                    'clip_status' => $shot->clip_status,
                    // Đang làm video: chưa gửi Muse thì đang đọc lời / chờ lượt, đã có mã thì Muse đang làm clip.
                    // Đang làm video: voice (đọc lời / chờ lượt Muse), muse (Muse làm clip), sync (đổi giọng tiếng Muse cho khớp khẩu hình).
                    'clip_stage' => $shot->clip_status === 'pending' ? ($shot->clip_stage ?? ($shot->clip_request_id ? 'muse' : 'voice')) : null,
                    'sync_warning' => $shot->sync_warning,
                    'can_resync' => $shot->clip_path !== null && $shot->clip_status !== 'pending',
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
            'cameraMoves' => EpisodeWriter::CAMERA_MOVES,
            'kinds' => Series::KINDS,
            'voiceEngines' => Voices::ENGINES,
            'dubVoices' => ShotDub::MODES,
            'aspects' => EpisodeRenderer::aspectsFor($series),
            'musicTracks' => $series->user->audioTracks()->latest()->get(['id', 'name', 'duration_ms']),
            'pricing' => [
                'write' => self::writePrice($series, $sequenceCount),
                'image' => CreditPricing::settings()['steps']['image'],
                'rewrite' => CreditPricing::aiEstimates()['film_episode'],
                'clip' => AnimateShotJob::price(),
                'render' => RenderEpisodeJob::price(),
                'sounds' => CreditPricing::aiEstimates()['film_episode'],
                'translate' => CreditPricing::aiEstimates()['film_episode'],
                'titles' => CreditPricing::aiEstimates()['scene_prompt_rewrite'],
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
            'kind' => ['nullable', Rule::in(array_keys(Series::KINDS))],
            'brief' => ['nullable', 'string', 'max:8000'],
            'character_ids' => ['array'],
            'character_ids.*' => ['integer', Rule::exists('series_characters', 'id')->where('series_id', $series->id)],
        ], [], ['title' => 'tên tập', 'synopsis' => 'tóm tắt', 'character_ids.*' => 'nhân vật', 'brief' => 'kịch bản có sẵn']);

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
        if ($message = Credits::shortfall($request->user(), self::writePrice($series, $sequences), 'viết tập')) {
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

        return back()->with('status', $series->musicVideo()
            ? 'Claude đang viết phân cảnh MV theo bài hát (mỗi shot một mốc của bài, câu hát, hát nhép hay không). Khoảng 1–3 phút...'
            : ($series->silent()
            ? 'Claude đang viết trọn gói tập: các shot (ảnh đầu cảnh, chuyển động, âm thanh), hook, caption, checklist. Khoảng 1–2 phút...'
            : 'Claude đang viết dàn ý rồi từng phân đoạn của tập, mất vài phút...'));
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
            'camera_move' => ['nullable', Rule::in(array_keys(EpisodeWriter::CAMERA_MOVES))],
            'motion' => ['nullable', 'string', 'max:1500'],
            'sound_cue' => ['nullable', 'string', 'max:500'],
            'animate' => ['nullable', 'boolean'],
        ], [], ['action' => 'hành động', 'dialogue.*.line' => 'lời thoại', 'seconds' => 'thời lượng', 'motion' => 'chuyển động']);

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
            ...collect($validated)->only(['camera_move', 'motion', 'sound_cue'])->map(fn ($v) => filled($v) ? trim((string) $v) : null)->all(),
            ...(array_key_exists('animate', $validated) && $validated['animate'] !== null ? ['animate' => (bool) $validated['animate']] : []),
        ]);
        $episode->update(['duration_ms' => (int) $episode->shots()->sum('duration_ms')]);
        $episode->syncStatus();

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
    public function voices(Request $request, Series $series, Episode $episode): RedirectResponse
    {
        $this->authorize('update', $series);
        $validated = $request->validate(['engine' => ['nullable', Rule::in(array_keys(Voices::ENGINES))]]);
        if (filled($validated['engine'] ?? null) && $validated['engine'] !== $episode->voice_engine) {
            if ($episode->shots()->where('clip_status', 'pending')->exists()) {
                return back()->with('status', 'Đang làm video, đợi xong rồi đổi cách đọc.');
            }
            $episode->update(['voice_engine' => $validated['engine']]);
        }
        $characters = $series->characters()->get()->keyBy('id');
        $shots = $episode->shots()->where('clip_status', '!=', 'pending')->get()->each->setRelation('episode', $episode)
            ->reject(fn (Shot $shot) => ShotAudio::isCurrent($shot, $characters) || ShotAudio::isPending($shot));
        if ($shots->isEmpty()) {
            return back()->with('status', 'Lời thoại mọi shot đã được đọc theo giọng hiện tại.');
        }
        foreach ($shots as $shot) {
            ShotAudio::markPending($shot);
            SynthesizeShotAudioJob::dispatch($shot);
        }

        return back()->with('status', $episode->voice_engine === 'expressive'
            ? "Đang đọc biểu cảm {$shots->count()} shot bằng Chatterbox (miễn phí, khoảng 1 phút mỗi câu, chạy nền; shot nào xong nghe được ngay)..."
            : "Đang đọc lời thoại {$shots->count()} shot (miễn phí, vài giây mỗi shot)...");
    }

    /**
     * Ghép cả tập: thẻ tiêu đề, mọi shot theo thứ tự (shot chưa có video dùng khung hình + lời thoại), thẻ kết, nhạc nền, phụ đề.
     * lang = vi: bản lồng tiếng Việt, phụ đề tiếng Anh chèn vào hình (Claude dịch lời nếu chưa dịch / lời đã đổi; lồng tiếng chạy trên máy).
     */
    public function render(Request $request, Series $series, Episode $episode): RedirectResponse
    {
        $this->authorize('update', $series);
        $validated = $request->validate([
            'title_card' => ['boolean'],
            'end_card' => ['boolean'],
            'burn_subtitles' => ['boolean'],
            'music_track_id' => ['nullable', 'integer', Rule::exists('audio_tracks', 'id')->where('user_id', $series->user_id)],
            'music_volume' => ['nullable', 'integer', 'min:0', 'max:100'],
            'sound_effects' => ['boolean'],
            'aspect' => ['nullable', Rule::in(array_keys(EpisodeRenderer::aspectsFor($series)))],
            'dialogue' => ['boolean'],
            'original_score' => ['boolean'],
            'lang' => ['nullable', Rule::in(['en', 'vi'])],
            'vi_voice' => ['nullable', Rule::in(array_keys(ShotDub::MODES))],
        ]);
        if ($series->musicVideo()) {
            // MV: tiếng là bài hát gốc; chỉ chọn khung xuất và có chèn lời vào hình hay không.
            $validated = [...collect($validated)->only(['aspect', 'burn_subtitles'])->all(), ...collect(EpisodeRenderer::SONG_OPTIONS)->except('song')->all()];
        }
        $lang = $validated['lang'] ?? 'en';
        $column = fn (string $name) => RenderEpisodeJob::column($lang, $name);
        if ($episode->{$column('render_status')} === 'rendering') {
            return back()->with('status', $lang === 'vi' ? 'Bản tiếng Việt đang được xuất.' : 'Tập đang được ghép.');
        }
        $shots = $episode->shots()->get(['id', 'image_status', 'image_path', 'video_path', 'clip_status']);
        if ($shots->isEmpty()) {
            return back()->with('status', 'Tập chưa có shot nào.');
        }
        if ($shots->contains(fn (Shot $shot) => $shot->clip_status === 'pending')) {
            return back()->with('status', 'Còn shot đang làm video, đợi xong rồi ghép.');
        }
        $missing = $shots->filter(fn (Shot $shot) => $shot->video_path === null && $shot->image_path === null)->count();
        if ($missing > 0) {
            return back()->with('status', "Còn {$missing} shot chưa có khung hình lẫn video. Vẽ khung hình hoặc tạo video trước.");
        }
        // Bật âm thanh hiệu ứng mà tập chưa có danh sách: Claude chọn ngay trong lượt ghép này (một nút làm cả hai).
        $planSounds = ($validated['sound_effects'] ?? true) && empty($episode->sound_plan) && SoundLibrary::installed();
        $translate = $lang === 'vi' && ! EpisodeTranslator::isCurrent($episode);
        $compose = ($validated['original_score'] ?? false) && empty($episode->score['title']);
        if ($compose && $episode->score_status === 'composing') {
            return back()->with('status', 'Claude đang sáng tác nhạc, đợi xong rồi ghép.');
        }
        $price = RenderEpisodeJob::price() + ($planSounds ? CreditPricing::aiEstimates()['film_episode'] : 0) + ($translate ? CreditPricing::aiEstimates()['film_episode'] : 0)
            + ($compose ? CreditPricing::aiEstimates()['film_episode'] : 0);
        if ($message = Credits::shortfall($request->user(), $price, $lang === 'vi' ? 'xuất bản tiếng Việt' : 'ghép tập')) {
            return back()->with('status', $message);
        }
        if ($planSounds && $episode->sound_status === 'planning') {
            return back()->with('status', 'Claude đang chọn âm thanh, đợi xong rồi ghép.');
        }

        $run = Str::lower(Str::random(8));
        $options = [...EpisodeRenderer::defaults($series), ...collect($validated)->except(['lang', 'vi_voice'])->map(fn ($v, $k) => in_array($k, ['title_card', 'end_card', 'burn_subtitles', 'sound_effects', 'dialogue', 'original_score'], true) ? (bool) $v : $v)->all(), 'run' => $run, 'plan_sounds' => $planSounds];
        if ($lang === 'vi') {
            // Phụ đề tiếng Anh luôn chèn vào hình ở bản tiếng Việt.
            $options = [...$options, 'lang' => 'vi', 'burn_subtitles' => true, 'vi_voice' => $validated['vi_voice'] ?? 'plain'];
        }
        $episode->update([
            $column('render_status') => 'rendering', $column('render_progress') => 0, $column('render_error') => null, $column('render_options') => $options,
            ...($planSounds ? ['sound_status' => 'planning', 'sound_error' => null] : []),
        ]);
        RenderEpisodeJob::dispatch($episode, $run, $lang);
        $stills = $shots->whereNull('video_path')->count();
        if ($lang === 'vi') {
            return back()->with('status', ($translate ? 'Claude đang dịch lời sang tiếng Việt, rồi lồng tiếng từng shot và ghép' : 'Đang lồng tiếng Việt từng shot rồi ghép')
                .' (phụ đề tiếng Anh). '.(($validated['vi_voice'] ?? 'plain') === 'character' ? 'Đổi giọng nhân vật chạy trên máy, có thể mất 30–60 phút.' : 'Vài phút.').' Trang tự cập nhật.');
        }

        return back()->with('status', ($planSounds ? 'Claude đang chọn âm thanh hiệu ứng, rồi ghép cả tập' : 'Đang ghép cả tập').($stills > 0 ? " ({$stills} shot chưa có video sẽ dùng khung hình + lời thoại)" : '').'. Vài phút, trang tự cập nhật.');
    }

    /** Claude chọn âm thanh hiệu ứng cho cả tập từ kho miễn phí (thay danh sách cũ). */
    public function planSounds(Request $request, Series $series, Episode $episode): RedirectResponse
    {
        $this->authorize('update', $series);
        if ($episode->sound_status === 'planning') {
            return back()->with('status', 'Claude đang chọn âm thanh.');
        }
        if ($episode->shots()->doesntExist()) {
            return back()->with('status', 'Tập chưa có shot nào.');
        }
        if (! SoundLibrary::installed()) {
            return back()->with('status', 'Chưa có kho âm thanh. Admin chạy: php artisan film:sound-library');
        }
        if ($message = Credits::shortfall($request->user(), CreditPricing::aiEstimates()['film_episode'], 'chọn âm thanh')) {
            return back()->with('status', $message);
        }
        $episode->update(['sound_status' => 'planning', 'sound_error' => null]);
        PlanEpisodeSoundsJob::dispatch($episode);

        return back()->with('status', 'Claude đang chọn âm thanh hiệu ứng cho từng shot (khoảng 1 phút)...');
    }

    /** Bỏ một âm thanh khỏi danh sách của tập (theo vị trí trong danh sách). */
    public function removeSound(Series $series, Episode $episode, int $index): RedirectResponse
    {
        $this->authorize('update', $series);
        $plan = $episode->sound_plan ?? [];
        if (isset($plan[$index])) {
            array_splice($plan, $index, 1);
            $episode->update(['sound_plan' => array_values($plan)]);
        }

        return back()->with('status', 'Đã bỏ âm thanh. Ghép lại tập để áp dụng.');
    }

    /** @return list<array{index: int, place: string, shot_id: ?int, shot_number: ?int, sound: string, character: ?string, description: string, url: ?string, at_ms: int, volume: float}> */
    private static function soundItems(Episode $episode, $shots): array
    {
        $library = SoundLibrary::byId();
        $numbers = $shots->pluck('id')->flip();
        $names = $episode->series->characters()->pluck('name', 'id');

        return collect($episode->sound_plan ?? [])->map(fn ($event, $i) => [
            'index' => $i,
            'place' => $event['place'],
            'shot_id' => $event['shot_id'],
            'shot_number' => $event['shot_id'] !== null && isset($numbers[$event['shot_id']]) ? $numbers[$event['shot_id']] + 1 : null,
            'sound' => $event['sound'],
            'character' => isset($event['character_id']) ? ($names[$event['character_id']] ?? null) : null,
            'description' => $library[$event['sound']]['description'] ?? $event['sound'],
            'url' => Media::url($library[$event['sound']]['files'][0] ?? null),
            'at_ms' => $event['at_ms'],
            'volume' => $event['volume'],
        ])->values()->all();
    }

    /** Các đoạn nhạc riêng của tập để nghe thử. @return list<array{place: string, label: string, mood: ?string, style: string, tempo: int, lead: string, url: ?string}> */
    private static function scoreCues(Episode $episode, $shots): array
    {
        $score = $episode->score;
        if (empty($score['title'])) {
            return [];
        }
        $titles = collect($episode->outline['sequences'] ?? []);
        $cue = fn (string $place, string $label, array $cue, ?string $mood, ?string $path) => [
            'place' => $place, 'label' => $label, 'mood' => $mood, 'style' => $cue['style'], 'tempo' => $cue['tempo'], 'lead' => $cue['lead'], 'url' => Media::url($path),
        ];

        return [
            $cue('title', 'Nhạc hiệu (thẻ tiêu đề)', $score['title'], null, $score['previews']['title'] ?? null),
            ...collect($score['sequences'])->map(fn ($s, $i) => $cue('sequence', 'Phân đoạn '.$s['sequence'].(($t = $titles[$s['sequence'] - 1]['title'] ?? null) ? ": {$t}" : ''), $s['cue'], $s['mood'], $score['previews']['sequences'][$i] ?? null))->all(),
            $cue('end', 'Nhạc kết (thẻ kết)', $score['end'], null, $score['previews']['end'] ?? null),
        ];
    }

    /** Claude sáng tác (lại) nhạc riêng của tập. */
    public function composeScore(Request $request, Series $series, Episode $episode): RedirectResponse
    {
        $this->authorize('update', $series);
        if ($episode->score_status === 'composing') {
            return back()->with('status', 'Claude đang sáng tác nhạc.');
        }
        if ($episode->shots()->doesntExist()) {
            return back()->with('status', 'Tập chưa có shot nào.');
        }
        if ($message = Credits::shortfall($request->user(), CreditPricing::aiEstimates()['film_episode'], 'sáng tác nhạc')) {
            return back()->with('status', $message);
        }
        $episode->update(['score_status' => 'composing', 'score_error' => null]);
        ComposeScoreJob::dispatch($episode);

        return back()->with('status', 'Claude đang sáng tác nhạc riêng cho tập (khoảng 1–2 phút)...');
    }

    /** Tạo lại ảnh bìa của một bản xuất (chọn khung hình / chữ khác). Miễn phí. */
    public function thumbnail(Request $request, Series $series, Episode $episode, EpisodeRender $render): RedirectResponse
    {
        $this->authorize('update', $series);
        abort_unless($render->episode_id === $episode->id, 404);
        $validated = $request->validate(['shot_id' => ['nullable', 'integer'], 'text' => ['nullable', 'string', 'max:60'], 'art' => ['boolean']]);
        try {
            Thumbnail::forRender($render, $validated['shot_id'] ?? null, $validated['text'] ?? null, (bool) ($validated['art'] ?? false));
        } catch (RuntimeException $e) {
            return back()->with('status', $e->getMessage());
        }

        return back()->with('status', 'Đã tạo lại ảnh bìa.');
    }

    /** Muse vẽ riêng ảnh nền cho ảnh bìa (nhân vật to, biểu cảm mạnh, chừa chỗ cho chữ), xong thì in chữ lên. */
    public function thumbnailArt(Request $request, Series $series, Episode $episode, EpisodeRender $render): RedirectResponse
    {
        $this->authorize('update', $series);
        abort_unless($render->episode_id === $episode->id, 404);
        $validated = $request->validate(['text' => ['nullable', 'string', 'max:60'], 'notes' => ['nullable', 'string', 'max:500']]);
        if ($render->art_status === 'pending') {
            return back()->with('status', 'Muse đang vẽ ảnh bìa.');
        }
        if (Thumbnail::artCharacters($render)->isEmpty()) {
            return back()->with('status', 'Nhân vật của tập chưa có ảnh chính để Muse vẽ ảnh bìa.');
        }
        // Chưa có chữ và chưa có tiêu đề gợi ý: Claude gợi ý tiêu đề trước khi vẽ.
        $text = EpisodeTitler::placeholder($validated['text'] ?? null) ? null : $validated['text'];
        $titles = $text === null && empty($episode->title_ideas);
        $price = CreditPricing::settings()['steps']['image'] + ($titles ? CreditPricing::aiEstimates()['scene_prompt_rewrite'] : 0);
        if ($message = Credits::shortfall($request->user(), $price, 'vẽ ảnh bìa')) {
            return back()->with('status', $message);
        }

        $render->update(['art_status' => 'pending', 'art_request_id' => null, 'art_error' => null]);
        GenerateThumbnailArtJob::dispatch($render, Thumbnail::artPrompt($render, $validated['notes'] ?? null), $text);

        return back()->with('status', ($titles ? 'Claude gợi ý tiêu đề, rồi ' : '').'Muse vẽ ảnh bìa riêng (vài phút), xong sẽ tự in chữ lên.');
    }

    /** Claude gợi ý vài tiêu đề video cho tập (kèm chữ cho ảnh bìa). */
    public function suggestTitles(Request $request, Series $series, Episode $episode): RedirectResponse
    {
        $this->authorize('update', $series);
        if ($episode->title_status === 'suggesting') {
            return back()->with('status', 'Claude đang nghĩ tiêu đề.');
        }
        if ($message = Credits::shortfall($request->user(), CreditPricing::aiEstimates()['scene_prompt_rewrite'], 'gợi ý tiêu đề')) {
            return back()->with('status', $message);
        }
        $episode->update(['title_status' => 'suggesting', 'title_error' => null]);
        SuggestTitlesJob::dispatch($episode);

        return back()->with('status', 'Claude đang gợi ý tiêu đề (khoảng nửa phút)...');
    }

    /** Dùng một tiêu đề Claude gợi ý làm tên tập (và tiêu đề trong gói đăng bài). */
    public function useTitle(Request $request, Series $series, Episode $episode): RedirectResponse
    {
        $this->authorize('update', $series);
        $validated = $request->validate(['index' => ['required', 'integer', 'min:0']]);
        $idea = ($episode->title_ideas ?? [])[$validated['index']] ?? null;
        abort_if($idea === null, 404);
        $episode->update([
            'title' => $idea['title'],
            ...(is_array($episode->package) ? ['package' => [...$episode->package, 'title' => $idea['title']]] : []),
        ]);

        return back()->with('status', 'Đã đổi tên tập. Ảnh bìa các bản đã xuất vẫn giữ chữ cũ: bấm "Tạo lại ảnh bìa" để cập nhật.');
    }

    public function downloadThumbnail(Series $series, Episode $episode, EpisodeRender $render): StreamedResponse|RedirectResponse
    {
        $this->authorize('view', $series);
        abort_unless($render->episode_id === $episode->id, 404);
        if ($render->thumbnail_path === null) {
            return back()->with('status', 'Bản này chưa có ảnh bìa.');
        }

        return Media::disk()->download($render->thumbnail_path, $render->fileName().'-thumbnail.jpg');
    }

    /** Tải một bản đã xuất. */
    public function downloadVersion(Series $series, Episode $episode, EpisodeRender $render): StreamedResponse
    {
        $this->authorize('view', $series);
        abort_unless($render->episode_id === $episode->id, 404);

        return Media::disk()->download($render->path, $render->fileName().'.mp4');
    }

    public function versionSubtitles(Series $series, Episode $episode, EpisodeRender $render): StreamedResponse|RedirectResponse
    {
        $this->authorize('view', $series);
        abort_unless($render->episode_id === $episode->id, 404);
        if ($render->srt_path === null) {
            return back()->with('status', 'Bản này không có phụ đề.');
        }

        return Media::disk()->download($render->srt_path, $render->fileName().'.en.srt');
    }

    /** Xoá một bản đã xuất (xoá file); tập trỏ sang bản mới nhất còn lại. */
    public function destroyVersion(Series $series, Episode $episode, EpisodeRender $render): RedirectResponse
    {
        $this->authorize('update', $series);
        abort_unless($render->episode_id === $episode->id, 404);
        Media::delete($render->path);
        Media::delete($render->srt_path);
        Media::delete($render->thumbnail_path);
        Media::delete($render->art_path);
        $render->delete();
        $column = fn (string $name) => RenderEpisodeJob::column($render->lang, $name);
        if ($episode->{$column('render_path')} === $render->path) {
            $latest = $episode->renders()->where('lang', $render->lang)->first();
            $episode->update([$column('render_path') => $latest?->path, $column('srt_path') => $latest?->srt_path]);
        }

        return back()->with('status', 'Đã xoá bản xuất.');
    }

    /** Tải video tập (?lang=vi: bản lồng tiếng Việt). */
    public function downloadRender(Request $request, Series $series, Episode $episode): StreamedResponse|RedirectResponse
    {
        $this->authorize('view', $series);
        $vi = $request->query('lang') === 'vi';
        $path = $vi ? $episode->vi_render_path : $episode->render_path;
        if ($path === null) {
            return back()->with('status', $vi ? 'Chưa xuất bản tiếng Việt.' : 'Tập chưa được ghép.');
        }

        $options = ($vi ? $episode->vi_render_options : $episode->render_options) ?? [];
        $suffix = ($vi ? '-tieng-viet' : '').(str_starts_with((string) ($options['aspect'] ?? ''), '9:16') ? '-doc' : '').(($options['dialogue'] ?? true) || $vi ? '' : '-khong-loi');

        return Media::disk()->download($path, Str::slug($series->title).'-tap-'.$episode->number.$suffix.'.mp4');
    }

    /** Phụ đề tiếng Anh (.srt); ?lang=vi: theo mốc bản lồng tiếng Việt. */
    public function downloadSubtitles(Request $request, Series $series, Episode $episode): StreamedResponse|RedirectResponse
    {
        $this->authorize('view', $series);
        $vi = $request->query('lang') === 'vi';
        $path = $vi ? $episode->vi_srt_path : $episode->srt_path;
        if ($path === null) {
            return back()->with('status', $vi ? 'Chưa xuất bản tiếng Việt.' : 'Tập chưa được ghép.');
        }

        return Media::disk()->download($path, Str::slug($series->title).'-tap-'.$episode->number.($vi ? '-tieng-viet' : '').'.en.srt');
    }

    /** Tải lời thoại cả tập (.zip): tiếng từng shot, một file ghép cả tập theo đúng thời lượng shot, kịch bản lời kèm mốc thời gian. */
    public function downloadDialogue(Series $series, Episode $episode): BinaryFileResponse|RedirectResponse
    {
        $this->authorize('view', $series);
        if ($episode->shots()->whereNotNull('audio_path')->doesntExist()) {
            return back()->with('status', 'Chưa có lời thoại nào được đọc. Bấm "Đọc lời thoại" trước.');
        }

        return response()->download(EpisodeDialogue::zip($episode), EpisodeDialogue::fileName($episode).'.zip')->deleteFileAfterSend();
    }

    /** Tải tiếng thoại của một shot (.wav). */
    public function downloadShotAudio(Series $series, Episode $episode, Shot $shot): StreamedResponse|RedirectResponse
    {
        $this->authorize('view', $series);
        if ($shot->audio_path === null) {
            return back()->with('status', 'Shot chưa có lời thoại đã đọc.');
        }

        return Media::disk()->download($shot->audio_path, EpisodeDialogue::fileName($episode).'-shot-'.EpisodeDialogue::shotNumber($shot).'.wav');
    }

    /** Muse làm clip cho mọi shot đã có khung hình mà chưa có video (hoặc lỗi), mỗi shot ghép với lời thoại thành video. */
    public function clips(Request $request, Series $series, Episode $episode): RedirectResponse
    {
        $this->authorize('update', $series);
        if ($episode->status === 'writing') {
            return back()->with('status', 'Tập đang được viết, đợi xong đã.');
        }
        $shots = $episode->shots()->where('image_status', 'ready')->whereIn('clip_status', ['none', 'failed'])
            // Truyện ngắn: chỉ shot chọn "Clip AI"; còn lại ghép bằng ảnh tĩnh + chuyển động máy.
            ->when($series->silent(), fn ($q) => $q->where('animate', true))->get();
        if ($shots->isEmpty()) {
            return back()->with('status', $series->silent() && $episode->shots()->where('animate', true)->doesntExist()
                ? 'Chưa có shot nào chọn "Clip AI". Bật "Clip AI" ở shot cần chuyển động thật.'
                : ($episode->shots()->where('image_status', '!=', 'ready')->exists()
                ? 'Shot cần có khung hình trước khi làm video. Bấm "Vẽ khung hình" trước.'
                : 'Mọi shot đã có video (hoặc đang làm).'));
        }
        if ($message = Credits::shortfall($request->user(), AnimateShotJob::price() * $shots->count(), "làm {$shots->count()} clip")) {
            return back()->with('status', $message);
        }

        foreach ($shots as $shot) {
            self::queueClip($shot);
        }
        $episode->update(['status' => 'animating', 'error' => null]);

        return back()->with('status', $series->silent()
            ? "Muse đang làm clip {$shots->count()} shot (vài phút mỗi shot). Các shot còn lại sẽ là ảnh tĩnh + chuyển động máy khi ghép."
            : "Muse đang làm video {$shots->count()} shot (đọc lời thoại trước, rồi làm clip khớp môi; vài phút mỗi shot, chạy song song vài shot).");
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

    /** Muse từ chối làm clip: Claude viết lại chuyển động của shot (theo lý do từ chối, giữ khung hình) rồi Muse làm lại clip. */
    public function rewriteClip(Request $request, Series $series, Episode $episode, Shot $shot): RedirectResponse
    {
        $this->authorize('update', $series);
        abort_unless($shot->episode_id === $episode->id, 404);
        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);
        if ($shot->clip_status === 'pending' || $episode->status === 'writing') {
            return back()->with('status', 'Shot đang làm video hoặc tập đang được viết.');
        }
        if ($shot->image_status !== 'ready') {
            return back()->with('status', 'Shot cần có khung hình trước.');
        }
        $price = AnimateShotJob::price() + CreditPricing::aiEstimates()['film_episode'];
        if ($message = Credits::shortfall($request->user(), $price, 'viết lại chuyển động và làm clip')) {
            return back()->with('status', $message);
        }

        $reason = ShotRewriter::clipRejection($shot);
        $shot->update(['clip_status' => 'pending', 'clip_stage' => 'rewrite', 'clip_request_id' => null, 'clip_error' => null]);
        RewriteShotClipJob::dispatch($shot, $reason, $validated['notes'] ?? null);
        $episode->update(['status' => 'animating']);

        return back()->with('status', 'Claude đang viết lại chuyển động của shot, xong thì Muse làm lại clip...');
    }

    /** Khớp lại tiếng cho clip đã có (đổi giọng tiếng Muse sang giọng nhân vật hiện tại), không gọi Muse, không tốn Credits. */
    public function resync(Series $series, Episode $episode, Shot $shot): RedirectResponse
    {
        $this->authorize('update', $series);
        if ($shot->clip_path === null || $shot->clip_status === 'pending') {
            return back()->with('status', 'Shot chưa có clip Muse hoặc đang làm video.');
        }
        $shot->update(['clip_status' => 'pending', 'clip_stage' => 'sync', 'clip_error' => null]);
        AnimateShotJob::dispatch($shot);
        $episode->update(['status' => 'animating']);

        return back()->with('status', 'Đang đổi giọng tiếng Muse sang giọng nhân vật cho khớp khẩu hình (miễn phí, khoảng nửa phút mỗi câu)...');
    }

    /** Bật / tắt làm clip AI cho một shot (truyện ngắn: shot tắt thì ghép bằng ảnh tĩnh + chuyển động máy). */
    public function toggleAnimate(Request $request, Series $series, Episode $episode, Shot $shot): RedirectResponse
    {
        $this->authorize('update', $series);
        abort_unless($shot->episode_id === $episode->id, 404);
        $validated = $request->validate(['animate' => ['required', 'boolean']]);
        $shot->update(['animate' => (bool) $validated['animate']]);
        $episode->syncStatus();

        return back()->with('status', $shot->animate ? 'Shot này sẽ làm clip AI.' : 'Shot này sẽ dùng ảnh tĩnh + chuyển động máy khi ghép.');
    }

    /** Credits ước tính để viết tập: truyện ngắn một lượt trọn gói (dài hơn một phân đoạn), phim nhiều tập dàn ý + từng phân đoạn. */
    private static function writePrice(Series $series, int $sequences): int
    {
        return CreditPricing::aiEstimates()['film_episode'] * ($series->silent() || $series->musicVideo() ? 2 : $sequences + 1);
    }

    /** Tuỳ chọn ghép lần trước (bỏ khung không hợp với phim), mặc định theo loại phim. */
    private static function renderOptions(Series $series, Episode $episode): array
    {
        $options = [...EpisodeRenderer::defaults($series), ...collect($episode->render_options ?? [])->except(['run', 'plan_sounds', 'lang', 'vi_voice'])->all()];
        if (! isset(EpisodeRenderer::aspectsFor($series)[$options['aspect']])) {
            $options['aspect'] = EpisodeRenderer::defaults($series)['aspect'];
        }

        return $options;
    }

    public static function queueClip(Shot $shot, ?string $notes = null): void
    {
        $shot->update(['clip_status' => 'pending', 'clip_stage' => 'voice', 'clip_request_id' => null, 'clip_error' => null]);
        AnimateShotJob::dispatch($shot, $notes);
    }

    public static function queueImage(Shot $shot, ?string $notes = null): void
    {
        $shot->update(['image_status' => 'pending', 'request_id' => null, 'error' => null]);
        GenerateShotImageJob::dispatch($shot, ShotPrompt::build($shot, $notes));
    }
}
