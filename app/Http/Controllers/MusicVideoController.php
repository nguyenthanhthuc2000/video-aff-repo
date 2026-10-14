<?php

namespace App\Http\Controllers;

use App\Enums\SeriesStatus;
use App\Jobs\PlanSeriesJob;
use App\Jobs\SuggestMusicVideoIdeasJob;
use App\Models\AudioTrack;
use App\Models\Series;
use App\Services\Film\MusicVideoWriter;
use App\Services\Film\SeriesPlanner;
use App\Services\Video\VideoProbe;
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
    public function create(Request $request): Response
    {
        $drivers = self::drivers();

        return Inertia::render('music-videos/create', [
            'songs' => $request->user()->audioTracks()->whereNotNull('path')->latest('id')->get()->map(fn (AudioTrack $t) => [
                'id' => $t->id, 'name' => $t->name, 'duration_ms' => $t->duration_ms, 'lyrics' => $t->lyrics, 'url' => Media::url($t->path),
                // Lời theo thời gian (dịch vụ audio): nghe thử thì câu đang hát sáng lên, bấm câu để tua tới.
                'vocal_status' => $t->vocal_status, 'vocal_error' => $t->vocal_error,
                'lines' => MusicVideoWriter::lines($t->vocal_analysis['words'] ?? []),
            ]),
            'stylePresets' => collect(Series::MV_STYLE_PRESETS)->map(fn ($p) => ['label' => $p['label'], 'style' => $p['style']]),
            'lipSyncModes' => Series::LIP_SYNC_MODES,
            'drivers' => $drivers,
            'defaultDriver' => collect($drivers)->filter(fn ($d) => $d['available'])->keys()->first(),
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
            'previous' => ['array', 'max:12'],
            'previous.*' => ['nullable', 'string', 'max:120'],
        ]);
        if ($message = Credits::shortfall($request->user(), CreditPricing::aiEstimates()['film_plan'], 'đề xuất ý tưởng MV')) {
            return response()->json(['message' => $message], 422);
        }
        $token = (string) Str::uuid();
        $input = [
            ...collect($validated)->except(['style_preset', 'style'])->all(),
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
     * Công cụ tạo ảnh + clip cho MV và có dùng được không (theo cấu hình .env).
     *
     * @return array<string, array{label: string, available: bool, note: string}>
     */
    public static function drivers(): array
    {
        $configured = config('videoaff.image_driver');
        $drivers = [
            'browser' => [
                'label' => 'Muse qua trình duyệt ngầm (miễn phí, vài phút mỗi clip)',
                'available' => $configured === 'browser',
                'note' => $configured === 'browser'
                    ? 'Đang bật. Cần đăng nhập Muse ở menu Xác thực Muse.'
                    : 'Chưa bật: đặt MUSE_DRIVER=browser trong .env rồi chạy lại app và queue.',
            ],
            'fal' => [
                'label' => 'fal API (trả phí theo lượt)',
                'available' => false,
                'note' => (filled(config('videoaff.fal.key')) ? 'Đã có FAL_KEY' : 'Chưa đặt FAL_KEY')
                    .'; fal trong app mới tạo được ảnh, chưa làm được clip chuyển động / hát nhép cho MV.',
            ],
        ];
        // Máy chưa nối Muse (test, phát triển): ảnh và clip giả để đi hết luồng.
        if (in_array($configured, ['fake', 'web'], true)) {
            $drivers['fake'] = ['label' => 'Giả lập (ảnh / clip mẫu, không tạo thật)', 'available' => true, 'note' => 'Chỉ để thử luồng khi chưa nối Muse.'];
        }

        return $drivers;
    }
}
