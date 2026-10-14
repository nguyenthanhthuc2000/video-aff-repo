<?php

namespace App\Http\Controllers;

use App\Jobs\ComposeSongDraftJob;
use App\Jobs\GenerateSongJob;
use App\Models\AudioTrack;
use App\Models\SongClip;
use App\Models\SongDraft;
use App\Models\SongTake;
use App\Models\VirtualBand;
use App\Services\Audio\YoutubeReference;
use App\Services\Film\HookClips;
use App\Services\Film\MusicVideoWriter;
use App\Services\Film\SeriesPlanner;
use App\Services\Film\SongAnalysis;
use App\Support\AiProviders;
use App\Support\CreditPricing;
use App\Support\Credits;
use App\Support\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Nhạc cho Shorts: kho bài hát của từng ban nhạc ảo. Claude viết bài gốc theo ban + chủ đề (link YouTube tham khảo không bắt buộc),
 * tạo nhiều bản nhạc rồi chọn bản hay nhất, nghe với lời chạy theo nhạc, gợi ý / lưu đoạn hook 15–40 giây dùng cho Shorts nhạc.
 */
class MusicStudioController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $bands = $user->virtualBands()->where('status', 'ready')->orderBy('name')->get(['id', 'name', 'genre', 'era']);
        $band = $bands->firstWhere('id', $request->integer('band')) ?? $bands->first();
        $songs = $band ? $band->songs()->with(['takes.audioTrack', 'audioTrack'])->latest('id')->get() : collect();
        $clips = $band ? $user->songClips()->where('virtual_band_id', $band->id)->with('audioTrack:id,name')->latest('id')->get() : collect();

        return Inertia::render('music-studio/index', [
            'bands' => $bands,
            'band' => $band?->only(['id', 'name', 'genre', 'era']),
            'songs' => $songs->map(fn (SongDraft $d) => self::present($d, $clips)),
            'clips' => $clips->map(fn (SongClip $c) => self::presentClip($c)),
            'providers' => SongDraftController::providers(),
            'musicDefault' => AiProviders::selected('music'),
            'creditsPerSong' => CreditPricing::aiEstimates()['film_plan'],
            'credits' => (int) $user->credit_balance,
            'sections' => SongAnalysis::SECTIONS,
            'maxMb' => (int) (config('videoaff.audio_max_kb') / 1024),
        ]);
    }

    /** @param  Collection<int, SongClip>  $clips */
    private static function present(SongDraft $draft, $clips): array
    {
        $track = $draft->audioTrack;

        return [
            'id' => $draft->id, 'topic' => $draft->topic, 'notes' => $draft->notes, 'source_url' => $draft->source_url,
            'status' => $draft->status, 'error' => $draft->error, 'is_busy' => $draft->isBusy(),
            'reference' => $draft->reference ? ['title' => $draft->reference['title'] ?? '', 'artist' => $draft->reference['artist'] ?? '', 'duration' => $draft->reference['duration'] ?? 0,
                'language' => $draft->reference['language'] ?? null, 'captions_kind' => $draft->reference['captions_kind'] ?? null, 'lines' => count($draft->reference['captions'] ?? [])] : null,
            'profile' => $draft->profile, 'song' => $draft->song, 'overlap' => $draft->overlap,
            'target_seconds' => $draft->targetSeconds(), 'estimates' => SongDraftController::estimates($draft),
            'music_provider' => $draft->music_provider, 'music_status' => $draft->music_status, 'music_error' => $draft->music_error,
            'audio_track_id' => $draft->audio_track_id,
            'takes' => $draft->takes->map(fn (SongTake $t) => [
                'id' => $t->id, 'audio_track_id' => $t->audio_track_id, 'name' => $t->audioTrack?->name, 'url' => Media::url($t->audioTrack?->path),
                'provider' => $t->provider, 'duration_ms' => $t->audioTrack?->duration_ms, 'chosen' => $t->audio_track_id === $draft->audio_track_id,
            ])->values(),
            'track' => $track ? [
                'id' => $track->id, 'name' => $track->name, 'url' => Media::url($track->path), 'duration_ms' => $track->duration_ms,
                'vocal_status' => $track->vocal_status, 'lines' => MusicVideoWriter::lines($track->vocal_analysis['words'] ?? []),
                'analysis' => $track->song_info['analysis'] ?? null, 'analysis_status' => $track->song_info['analysis_status'] ?? null,
                'analysis_error' => $track->song_info['analysis_error'] ?? null,
            ] : null,
            'clip_count' => $clips->where('audio_track_id', $draft->audio_track_id)->count(),
        ];
    }

    public static function presentClip(SongClip $clip): array
    {
        return [
            'id' => $clip->id, 'name' => $clip->name, 'audio_track_id' => $clip->audio_track_id, 'song' => $clip->audioTrack?->name,
            'start_ms' => $clip->start_ms, 'end_ms' => $clip->end_ms, 'seconds' => $clip->seconds(), 'kind' => $clip->kind, 'band_id' => $clip->virtual_band_id,
        ];
    }

    /** Claude viết nhiều bài mới cho ban nhạc: mỗi dòng một chủ đề (tối đa 10), link tham khảo dùng chung không bắt buộc. */
    public function storeSongs(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'band_id' => ['required', 'integer', Rule::exists('virtual_bands', 'id')->where('user_id', $request->user()->id)->where('status', 'ready')],
            'topics' => ['required', 'string', 'max:3000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'url' => ['nullable', 'url', 'max:2048', fn ($attribute, $value, $fail) => YoutubeReference::isYoutube($value) ? null : $fail('Chỉ nhận link YouTube.')],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => 'Cần xác nhận số bài và Credits.'], ['topics' => 'chủ đề', 'url' => 'link tham khảo']);
        $topics = array_slice(array_values(array_filter(array_map('trim', preg_split('/\R/u', $validated['topics']) ?: []))), 0, 10);
        if ($topics === []) {
            return back()->withErrors(['topics' => 'Nhập ít nhất một chủ đề.']);
        }
        if ($name = SeriesPlanner::blockedName(implode(' ', $topics).' '.($validated['notes'] ?? ''))) {
            return back()->withErrors(['topics' => "\"{$name}\" là nghệ sĩ / tác phẩm có thật: hãy viết chủ đề riêng."]);
        }
        $url = filled($validated['url'] ?? null) ? trim($validated['url']) : null;
        $price = CreditPricing::aiEstimates()['film_plan'] * ($url ? 2 : 1) * count($topics);
        if ($message = Credits::shortfall($request->user(), $price, 'viết '.count($topics).' bài hát')) {
            return back()->withErrors(['topics' => $message]);
        }
        foreach ($topics as $i => $topic) {
            $draft = $request->user()->songDrafts()->create([
                'virtual_band_id' => $validated['band_id'], 'source_url' => $url, 'topic' => mb_substr($topic, 0, 500),
                'notes' => filled($validated['notes'] ?? null) ? trim($validated['notes']) : null, 'status' => $url ? 'fetching' : 'writing',
            ]);
            ComposeSongDraftJob::dispatch($draft)->delay(now()->addSeconds($i * 15));
        }

        return back()->with('status', 'Claude đang viết '.count($topics).' bài hát mới (khoảng 1–2 phút mỗi bài)...');
    }

    /** Chọn bản nhạc dùng cho bài (MV, Shorts). */
    public function chooseTake(Request $request, SongDraft $songDraft, SongTake $take): RedirectResponse
    {
        abort_unless($songDraft->user_id === $request->user()->id && $take->song_draft_id === $songDraft->id, 403);
        $songDraft->update(['audio_track_id' => $take->audio_track_id]);

        return back()->with('status', 'Đã chọn bản nhạc.');
    }

    /** Tạo nhạc cho mọi bài của ban đã có lời mà chưa có nhạc (trả phí: xác nhận tổng chi phí). */
    public function generateAll(Request $request, VirtualBand $band): RedirectResponse
    {
        abort_unless($band->user_id === $request->user()->id, 403);
        $validated = $request->validate([
            'provider' => ['required', Rule::in(collect(SongDraftController::providers())->filter(fn ($p) => $p['available'] && $p['api'])->keys()->all())],
            'confirm_cost' => ['accepted'],
        ], ['provider.in' => 'Công cụ tạo nhạc này chưa dùng được.', 'confirm_cost.accepted' => 'Cần xác nhận chi phí trước khi tạo nhạc.']);
        $drafts = $band->songs()->where('status', 'ready')->whereNull('audio_track_id')->where(fn ($q) => $q->whereNull('music_status')->orWhere('music_status', '!=', 'pending'))->get();
        foreach ($drafts->values() as $i => $draft) {
            $draft->update(['music_provider' => $validated['provider'], 'music_status' => 'pending', 'music_request_id' => null, 'music_error' => null]);
            GenerateSongJob::dispatch($draft)->delay(now()->addSeconds($i * 10));
        }

        return back()->with('status', $drafts->isEmpty() ? 'Không có bài nào cần tạo nhạc.' : "Đang tạo nhạc cho {$drafts->count()} bài (vài phút mỗi bài)...");
    }

    /** Gợi ý đoạn hook (điệp khúc, câu lặp lại, đoạn sôi động) dài $seconds giây. */
    public function suggestClips(Request $request, AudioTrack $audioTrack): JsonResponse
    {
        $this->authorize('update', $audioTrack);
        $seconds = max(HookClips::MIN_SECONDS, min(HookClips::MAX_SECONDS, $request->integer('seconds', 25)));

        return response()->json(['suggestions' => HookClips::suggest($audioTrack, $seconds)]);
    }

    public function storeClip(Request $request, AudioTrack $audioTrack): RedirectResponse
    {
        $this->authorize('update', $audioTrack);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'start_ms' => ['required', 'integer', 'min:0'],
            'end_ms' => ['required', 'integer', 'gt:start_ms'],
            'kind' => ['nullable', Rule::in(['chorus', 'hook', 'energy', 'custom'])],
        ], [], ['name' => 'tên đoạn']);
        $length = $validated['end_ms'] - $validated['start_ms'];
        if ($length < HookClips::MIN_SECONDS * 1000 || $length > HookClips::MAX_SECONDS * 1000) {
            return back()->withErrors(['end_ms' => 'Đoạn hook phải dài 15–40 giây.']);
        }
        if ($audioTrack->duration_ms && $validated['end_ms'] > $audioTrack->duration_ms + 500) {
            return back()->withErrors(['end_ms' => 'Đoạn vượt quá cuối bài.']);
        }
        $band = SongDraft::where('user_id', $request->user()->id)->where('audio_track_id', $audioTrack->id)->value('virtual_band_id')
            ?? SongTake::where('audio_track_id', $audioTrack->id)->first()?->draft?->virtual_band_id;
        $request->user()->songClips()->create([
            ...$validated, 'kind' => $validated['kind'] ?? 'custom', 'audio_track_id' => $audioTrack->id, 'virtual_band_id' => $band,
        ]);

        return back()->with('status', "Đã lưu đoạn \"{$validated['name']}\": dùng được ở Shorts nhạc.");
    }

    public function destroyClip(Request $request, SongClip $clip): RedirectResponse
    {
        abort_unless($clip->user_id === $request->user()->id, 403);
        $clip->delete();

        return back()->with('status', 'Đã xoá đoạn hook.');
    }
}
