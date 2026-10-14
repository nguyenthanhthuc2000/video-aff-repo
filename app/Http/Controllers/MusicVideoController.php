<?php

namespace App\Http\Controllers;

use App\Enums\SeriesStatus;
use App\Jobs\AnalyzeSongJob;
use App\Jobs\MusicVideoAutopilotJob;
use App\Jobs\PlanSeriesJob;
use App\Jobs\SuggestMusicVideoIdeasJob;
use App\Models\AudioTrack;
use App\Models\Series;
use App\Models\SongDraft;
use App\Services\Film\MusicVideoStage;
use App\Services\Film\MusicVideoWriter;
use App\Services\Film\SeriesPlanner;
use App\Services\Film\SongAnalysis;
use App\Services\Video\VideoProbe;
use App\Support\AiProviders;
use App\Support\CreditPricing;
use App\Support\Credits;
use App\Support\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tạo MV ca nhạc: tải bài hát lên (hoặc chọn trong thư viện Âm thanh), lời bài hát, phong cách, khung hình, ca sĩ, ý tưởng, kiểu hát nhép,
 * công cụ tạo ảnh / clip. MV là một bộ phim một tập (dùng chung luồng phim: nhân vật, bối cảnh, khung hình, clip, ghép).
 */
class MusicVideoController extends Controller
{
    /** Danh sách MV: bước đang làm, tiến độ, ảnh đại diện, video đã ghép; bài mới Claude sáng tác. Lọc / tìm / sắp xếp ở giao diện. */
    public function index(Request $request): Response
    {
        $series = $request->user()->series()->where('format', 'music_video')->with([
            'song:id,name,duration_ms',
            'characters' => fn ($q) => $q->select('id', 'series_id', 'position', 'name', 'main_image_path', 'locked_at'),
            'episodes' => fn ($q) => $q->withCount([
                'shots',
                'shots as images_ready_count' => fn ($q) => $q->where('image_status', 'ready'),
                'shots as videos_ready_count' => fn ($q) => $q->whereNotNull('video_path'),
            ]),
        ])->latest('updated_at')->get();

        return Inertia::render('music-videos/index', [
            'musicVideos' => $series->map(function (Series $mv) {
                $stage = MusicVideoStage::of($mv);
                $episode = $mv->episodes->first();
                $thumbnail = $episode?->shots()->where('image_status', 'ready')->whereNotNull('image_path')->value('image_path')
                    ?? $mv->characters->first()?->main_image_path;
                $preset = $mv->mv_options['style_preset'] ?? null;

                return [
                    'id' => $mv->id,
                    'title' => $mv->title,
                    'song' => $mv->song?->name,
                    'seconds' => (int) round(($mv->songRange()[1] - $mv->songRange()[0]) / 1000),
                    'style_preset' => $preset,
                    'style_label' => Series::MV_STYLE_PRESETS[$preset]['label'] ?? 'Phong cách riêng',
                    'aspect_ratio' => $mv->aspect_ratio,
                    'lip_sync_label' => Series::LIP_SYNC_MODES[$mv->lipSyncMode()],
                    'stage' => $stage,
                    'error' => $mv->error ?? $episode?->error ?? $episode?->render_error,
                    'thumbnail_url' => Media::url($thumbnail),
                    'video_url' => Media::url($episode?->render_status === 'done' ? $episode->render_path : null),
                    'continue_url' => $stage['episode_id'] ? route('series.episodes.show', ['series' => $mv->id, 'episode' => $stage['episode_id']]) : route('series.show', $mv),
                    'download_url' => $episode?->render_path ? route('series.episodes.render.download', ['series' => $mv->id, 'episode' => $episode->id]) : null,
                    'band_id' => $mv->virtual_band_id,
                    'template' => $mv->mv_options['template'] ?? null,
                    // Nội dung đăng bài của MV ngắn (tên, mô tả có dòng AI-generated, hashtag).
                    'post' => $mv->mv_options['post'] ?? null,
                    'autopilot' => (bool) ($mv->mv_options['autopilot'] ?? false),
                    'autopilot_note' => $mv->mv_options['autopilot_note'] ?? null,
                    'updated_at' => $mv->updated_at,
                    'created_at' => $mv->created_at,
                ];
            }),
            'groups' => MusicVideoStage::GROUPS,
            'bands' => $request->user()->virtualBands()->orderBy('name')->get(['id', 'name']),
            'bandFilter' => $request->integer('band') ?: null,
            'stylePresets' => collect(Series::MV_STYLE_PRESETS)->map(fn ($p) => $p['label']),
            'drafts' => $request->user()->songDrafts()->with('audioTrack:id,name')->latest('updated_at')->get()->map(fn (SongDraft $d) => [
                'id' => $d->id,
                'title' => $d->song['title'] ?? null,
                'reference' => $d->reference['title'] ?? $d->source_url,
                'status' => $d->status,
                'is_busy' => $d->isBusy(),
                'has_music' => $d->audio_track_id !== null,
                'too_similar' => (bool) ($d->overlap['too_similar'] ?? false),
                'updated_at' => $d->updated_at,
            ]),
        ]);
    }

    /** Bật / tắt chạy tự động (ý tưởng → phân cảnh → khung hình → clip → ghép). */
    public function autopilot(Request $request, Series $series): RedirectResponse
    {
        $this->authorizeMusicVideo($series);
        $on = $request->boolean('on');
        $series->update(['mv_options' => [...($series->mv_options ?? []), 'autopilot' => $on, 'autopilot_note' => null]]);
        if ($on) {
            MusicVideoAutopilotJob::dispatch($series);
        }

        return back()->with('status', $on ? 'Đã bật chạy tự động cho MV.' : 'Đã tắt chạy tự động.');
    }

    /** Đổi tên MV. */
    public function rename(Request $request, Series $series): RedirectResponse
    {
        $this->authorizeMusicVideo($series);
        $validated = $request->validate(['title' => ['required', 'string', 'max:200']], [], ['title' => 'tên MV']);
        $series->update(['title' => trim($validated['title'])]);

        return back()->with('status', 'Đã đổi tên MV.');
    }

    /** Nhân bản MV: chép lựa chọn (bài hát, đoạn bài, lời, phong cách, khung, hát nhép, ý tưởng, ảnh ca sĩ có sẵn), không chép ảnh / clip đã tạo; Claude lên ý tưởng lại. */
    public function duplicate(Request $request, Series $series): RedirectResponse
    {
        $this->authorizeMusicVideo($series);
        if ($message = Credits::shortfall($request->user(), CreditPricing::aiEstimates()['film_plan'], 'lên kế hoạch MV')) {
            return back()->with('status', $message);
        }
        $copy = $series->replicate(['bible', 'status', 'error', 'autopilot']);
        $copy->fill([
            'title' => Str::limit("{$series->title} (bản sao)", 200, ''), 'status' => SeriesStatus::Planning,
            'mv_options' => [...($series->mv_options ?? []), 'autopilot' => false, 'autopilot_note' => null],
        ])->save();
        PlanSeriesJob::dispatch($copy);

        return to_route('series.show', $copy)->with('status', 'Đã nhân bản MV (giữ bài hát và lựa chọn). Claude đang lên ý tưởng mới...');
    }

    /** Xoá MV (bài hát vẫn ở thư viện Âm thanh). */
    public function destroy(Series $series): RedirectResponse
    {
        $this->authorizeMusicVideo($series);
        if ($series->isBusy()) {
            return back()->with('status', 'MV đang lên ý tưởng, đợi xong rồi xoá.');
        }
        $series->delete();

        return to_route('music-videos.index')->with('status', "Đã xoá MV \"{$series->title}\".");
    }

    private function authorizeMusicVideo(Series $series): void
    {
        $this->authorize('update', $series);
        abort_unless($series->musicVideo(), 404);
    }

    public function create(Request $request): Response
    {
        $drivers = self::drivers();

        return Inertia::render('music-videos/create', [
            'songs' => $request->user()->audioTracks()->whereNotNull('path')->latest('id')->get()->map(fn (AudioTrack $t) => [
                'id' => $t->id, 'name' => $t->name, 'duration_ms' => $t->duration_ms, 'lyrics' => $t->lyrics, 'url' => Media::url($t->path),
                'language' => $t->language,
                // Lời theo thời gian (dịch vụ audio): nghe thử thì câu đang hát sáng lên, bấm câu để tua tới.
                'vocal_status' => $t->vocal_status, 'vocal_error' => $t->vocal_error,
                'lines' => MusicVideoWriter::lines($t->vocal_analysis['words'] ?? []),
                // Claude phân tích bài hát (sửa được).
                'analysis' => $t->song_info['analysis'] ?? null,
                'analysis_status' => $t->song_info['analysis_status'] ?? null,
                'analysis_error' => $t->song_info['analysis_error'] ?? null,
                // Bài sáng tác mới: ý tưởng MV + ca sĩ Claude viết kèm.
                'mv_idea' => $t->song_info['mv_idea'] ?? null,
                'singer' => $t->song_info['singer'] ?? null,
            ]),
            'sections' => SongAnalysis::SECTIONS,
            // Bài mới sáng tác từ link YouTube tham khảo (5 bài gần nhất).
            'drafts' => $request->user()->songDrafts()->latest('id')->limit(5)->get()->map(fn (SongDraft $d) => [
                'id' => $d->id, 'source_url' => $d->source_url, 'notes' => $d->notes, 'status' => $d->status, 'error' => $d->error, 'is_busy' => $d->isBusy(),
                'reference' => $d->reference ? collect($d->reference)->only(['title', 'artist', 'duration', 'language', 'captions_kind'])->all() + ['lines' => count($d->reference['captions'] ?? [])] : null,
                'profile' => $d->profile, 'song' => $d->song, 'overlap' => $d->overlap,
                'target_seconds' => $d->targetSeconds(), 'estimates' => SongDraftController::estimates($d),
                'music_provider' => $d->music_provider, 'music_status' => $d->music_status, 'music_error' => $d->music_error, 'audio_track_id' => $d->audio_track_id,
            ]),
            'musicProviders' => SongDraftController::providers(),
            // Công cụ tạo nhạc mặc định (trang Kết nối AI).
            'musicDefault' => AiProviders::selected('music'),
            // Mở từ tab "Bài Claude sáng tác" của danh sách MV.
            'openDraft' => $request->integer('draft') ?: null,
            'stylePresets' => collect(Series::MV_STYLE_PRESETS)->map(fn ($p) => ['label' => $p['label'], 'style' => $p['style']]),
            'lipSyncModes' => Series::LIP_SYNC_MODES,
            'drivers' => $drivers,
            'defaultDriver' => AiProviders::selected('clip'),
            'maxMb' => (int) (config('videoaff.audio_max_kb') / 1024),
            'estimate' => CreditPricing::aiEstimates()['film_plan'],
        ]);
    }

    public function store(Request $request, VideoProbe $probe): RedirectResponse
    {
        $drivers = self::drivers();
        $validated = $request->validate([
            'song' => ['nullable', 'required_without:audio_track_id', 'file', 'mimes:mp3,wav,m4a', 'max:'.config('videoaff.audio_max_kb')],
            'audio_track_id' => ['nullable', 'required_without:song', 'integer', Rule::exists('audio_tracks', 'id')->where('user_id', $request->user()->id)->whereNotNull('path')],
            'rights' => ['accepted'],
            'song_start' => ['nullable', 'numeric', 'min:0'],
            'song_end' => ['nullable', 'numeric', 'min:1'],
            'lyrics' => ['nullable', 'string', 'max:20000'],
            // Để trống: Claude tự đề xuất ý tưởng ở bước lên kế hoạch.
            'idea' => ['nullable', 'string', 'max:8000'],
            'singer_name' => ['nullable', 'string', 'max:80'],
            'singer' => ['nullable', 'string', 'max:1000'],
            'singer_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'style_preset' => ['required', Rule::in(array_keys(Series::MV_STYLE_PRESETS))],
            'style' => ['nullable', 'string', 'max:500'],
            'aspect_ratio' => ['required', Rule::in(['16:9', '9:16'])],
            'lip_sync' => ['required', Rule::in(array_keys(Series::LIP_SYNC_MODES))],
            'driver' => ['required', Rule::in(collect($drivers)->filter(fn ($d) => $d['available'])->keys()->all())],
        ], [
            'song.required_without' => 'Tải bài hát lên (mp3, wav, m4a) hoặc chọn một bài trong thư viện.',
            'audio_track_id.required_without' => 'Tải bài hát lên (mp3, wav, m4a) hoặc chọn một bài trong thư viện.',
            'rights.accepted' => 'Cần xác nhận bạn có quyền dùng bài hát này cho MV.',
            'driver.in' => 'Công cụ này chưa dùng được cho MV.',
        ], ['song' => 'file bài hát', 'idea' => 'ý tưởng', 'singer_image' => 'ảnh ca sĩ']);

        if ($name = SeriesPlanner::blockedName(($validated['idea'] ?? '').' '.($validated['singer'] ?? ''))) {
            return back()->withErrors(['idea' => "Ý tưởng nhắc \"{$name}\" là nhân vật / phim có bản quyền. Hãy tạo nhân vật riêng."])->withInput();
        }
        if ($message = Credits::shortfall($request->user(), CreditPricing::aiEstimates()['film_plan'], 'lên kế hoạch MV')) {
            return back()->withErrors(['idea' => $message])->withInput();
        }

        self::useDriver($validated['driver']);
        $user = $request->user();
        if ($file = $request->file('song')) {
            $track = $user->audioTracks()->create([
                'name' => Str::limit(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'Bài hát', 200, ''),
                'path' => Media::store($file, "audio/{$user->id}"),
                'mime' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'rights_confirmed_at' => now(),
                'lyrics' => filled($validated['lyrics'] ?? null) ? trim($validated['lyrics']) : null,
            ]);
            $track->update(['duration_ms' => $probe->probe($file->getRealPath())['duration_ms'] ?? null]);
        } else {
            $track = AudioTrack::findOrFail($validated['audio_track_id']);
        }

        $start = (int) round(($validated['song_start'] ?? 0) * 1000);
        $end = isset($validated['song_end']) ? (int) round($validated['song_end'] * 1000) : $track->duration_ms;
        if ($end === null) {
            return back()->withErrors(['song_end' => 'Chưa đọc được độ dài bài hát: nhập giây kết thúc.'])->withInput();
        }
        $end = $track->duration_ms !== null ? min($end, $track->duration_ms) : $end;
        if ($end - $start < 10000 || $end - $start > 600000) {
            return back()->withErrors(['song_end' => 'Đoạn bài hát dùng cho MV phải dài từ 10 giây tới 10 phút.'])->withInput();
        }

        $singer = trim((string) ($validated['singer'] ?? ''));
        $singerName = trim((string) ($validated['singer_name'] ?? '')) ?: 'Singer';
        $series = $user->series()->create([
            'title' => Str::limit($track->name, 60),
            'idea' => trim((string) ($validated['idea'] ?? '')),
            'format' => 'music_video',
            'audio_track_id' => $track->id,
            'song_start_ms' => $start > 0 ? $start : null,
            'song_end_ms' => $end,
            'lyrics' => filled($validated['lyrics'] ?? null) ? trim($validated['lyrics']) : ($track->lyrics ?: null),
            'mv_options' => [
                'lip_sync' => $validated['lip_sync'],
                'driver' => $validated['driver'],
                'style_preset' => $validated['style_preset'],
                'singer_name' => $singerName,
                'singer' => $singer !== '' ? $singer : null,
            ],
            'genre' => 'music',
            'age_min' => 13,
            'age_max' => Series::AGE_MAX,
            'audience' => Series::MV_AUDIENCE,
            'made_for_kids' => false,
            'style' => filled($validated['style'] ?? null) ? trim($validated['style']) : Series::MV_STYLE_PRESETS[$validated['style_preset']]['style'],
            'aspect_ratio' => $validated['aspect_ratio'],
            'episode_count' => 1,
            'episode_seconds' => (int) ceil(($end - $start) / 1000),
            'subtitle_languages' => [],
            'status' => SeriesStatus::Planning,
        ]);
        // Ảnh ca sĩ: Claude xem ảnh để viết ngoại hình, ảnh thành ảnh chính (không cần vẽ lại).
        if ($image = $request->file('singer_image')) {
            $series->update(['seed_characters' => [[
                'name' => $singerName,
                'note' => $singer,
                'path' => Media::store($image, "series/{$series->id}/characters"),
            ]]]);
        }
        // Lời có mốc thời gian (Demucs + faster-whisper ở dịch vụ audio, miễn phí) để Claude cắt shot đúng câu hát.
        $track->analyzeVocals();
        PlanSeriesJob::dispatch($series);

        return to_route('series.show', $series)->with('status', 'Claude đang lên ý tưởng MV (ca sĩ / nhân vật, bối cảnh, câu chuyện), khoảng 1–3 phút. '
            .'Dịch vụ audio đang tách lời bài hát theo thời gian để cắt shot đúng câu hát.');
    }

    /** Claude phân tích (lại) bài hát trong thư viện. */
    public function analyzeSong(Request $request, AudioTrack $audioTrack): RedirectResponse
    {
        $this->authorize('update', $audioTrack);
        if (! $audioTrack->isPlayable()) {
            return back()->withErrors(['audio' => 'Bài hát chưa có file nhạc.']);
        }
        if (($audioTrack->song_info['analysis_status'] ?? null) === 'pending') {
            return back()->with('status', 'Claude đang phân tích bài hát.');
        }
        if ($message = Credits::shortfall($request->user(), CreditPricing::aiEstimates()['film_plan'], 'phân tích bài hát')) {
            return back()->withErrors(['audio' => $message]);
        }
        $audioTrack->update(['song_info' => [...($audioTrack->song_info ?? []), 'analysis_status' => 'pending', 'analysis_error' => null]]);
        AnalyzeSongJob::dispatch($audioTrack);

        return back()->with('status', 'Claude đang phân tích bài hát (khoảng 1 phút)...');
    }

    /** Lưu phân tích bài hát đã sửa. */
    public function updateAnalysis(Request $request, AudioTrack $audioTrack): RedirectResponse
    {
        $this->authorize('update', $audioTrack);
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'artist' => ['nullable', 'string', 'max:200'],
            'genre' => ['nullable', 'string', 'max:100'],
            'tempo_bpm' => ['nullable', 'integer', 'min:40', 'max:220'],
            'tempo_feel' => ['nullable', 'string', 'max:40'],
            'mood' => ['nullable', 'string', 'max:300'],
            'themes' => ['array', 'max:6'],
            'themes.*' => ['nullable', 'string', 'max:100'],
            'structure' => ['array', 'max:30'],
            'structure.*.section' => ['required', Rule::in(SongAnalysis::SECTIONS)],
            'structure.*.start' => ['required', 'numeric', 'min:0'],
            'structure.*.end' => ['required', 'numeric', 'gt:structure.*.start'],
            'summary' => ['nullable', 'string', 'max:1000'],
        ], ['structure.*.end.gt' => 'Mỗi đoạn phải kết thúc sau khi bắt đầu.']);
        $analysis = SongAnalysis::normalize([...($audioTrack->song_info['analysis'] ?? []), ...$validated], $audioTrack->duration_ms ? $audioTrack->duration_ms / 1000 : null);
        $audioTrack->update(['song_info' => [...($audioTrack->song_info ?? []), 'analysis' => $analysis, 'analysis_status' => 'ready']]);

        return back()->with('status', 'Đã lưu phân tích bài hát.');
    }

    /** Claude đề xuất vài ý tưởng MV (chạy nền): trả mã để trang hỏi lại kết quả. */
    public function ideas(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'song_name' => ['nullable', 'string', 'max:255'],
            'lyrics' => ['nullable', 'string', 'max:20000'],
            'style_preset' => ['nullable', Rule::in(array_keys(Series::MV_STYLE_PRESETS))],
            'style' => ['nullable', 'string', 'max:500'],
            'aspect_ratio' => ['nullable', Rule::in(['16:9', '9:16'])],
            'singer' => ['nullable', 'string', 'max:1000'],
            'hint' => ['nullable', 'string', 'max:2000'],
            'audio_track_id' => ['nullable', 'integer'],
            'previous' => ['array', 'max:12'],
            'previous.*' => ['nullable', 'string', 'max:120'],
        ]);
        if ($message = Credits::shortfall($request->user(), CreditPricing::aiEstimates()['film_plan'], 'đề xuất ý tưởng MV')) {
            return response()->json(['message' => $message], 422);
        }
        $token = (string) Str::uuid();
        $track = isset($validated['audio_track_id']) ? $request->user()->audioTracks()->find($validated['audio_track_id']) : null;
        $analysis = $track?->song_info['analysis'] ?? null;
        $input = [
            ...collect($validated)->except(['style_preset', 'style', 'audio_track_id'])->all(),
            // Phân tích bài hát của Claude (thể loại, nhịp, không khí, chủ đề) để ý tưởng hợp bài hơn.
            'song_brief' => trim(SongAnalysis::brief($analysis)."\n".($analysis['summary'] ?? '')) ?: null,
            'style' => filled($validated['style'] ?? null) ? $validated['style'] : Series::MV_STYLE_PRESETS[$validated['style_preset'] ?? 'cinematic']['style'],
        ];
        Cache::put(SuggestMusicVideoIdeasJob::key($token), ['user_id' => $request->user()->id, 'status' => 'pending'], now()->addMinutes(SuggestMusicVideoIdeasJob::TTL_MINUTES));
        SuggestMusicVideoIdeasJob::dispatch($request->user()->id, $token, $input);

        return response()->json(['token' => $token]);
    }

    /** Kết quả đề xuất ý tưởng: pending / done (kèm ý tưởng) / failed (kèm lỗi). */
    public function ideasStatus(Request $request, string $token): JsonResponse
    {
        $result = Cache::get(SuggestMusicVideoIdeasJob::key($token));
        abort_if($result === null || $result['user_id'] !== $request->user()->id, 404);

        return response()->json(collect($result)->except('user_id')->all());
    }

    /**
     * Công cụ tạo ảnh + clip cho MV (theo bước "Clip chuyển động" của trang Kết nối AI). Chọn ở trang tạo MV / Shorts cũng đổi lựa chọn chung.
     *
     * @return array<string, array{label: string, available: bool, note: string, cost: string}>
     */
    public static function drivers(): array
    {
        return AiProviders::options('clip');
    }

    /** Dùng công cụ đã chọn cho ảnh nhân vật, khung hình và clip (lựa chọn chung). */
    public static function useDriver(string $driver): void
    {
        AiProviders::save(['character_image' => $driver, 'keyframe' => $driver, 'clip' => $driver]);
    }
}
