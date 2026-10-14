<?php

namespace App\Http\Controllers;

use App\Enums\SeriesStatus;
use App\Jobs\GenerateBandJob;
use App\Jobs\MusicVideoAutopilotJob;
use App\Jobs\PlanSeriesJob;
use App\Models\AudioTrack;
use App\Models\Series;
use App\Models\VirtualBand;
use App\Services\Film\SeriesPlanner;
use App\Services\Film\ShortTemplates;
use App\Support\CreditPricing;
use App\Support\Credits;
use App\Support\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Series Shorts nhạc: ban nhạc ảo (Claude nghĩ ra hoặc tự nhập) + mẫu Shorts (Classic Lineup, VS, Trẻ vs Già, Hành trình, Fan đi phố)
 * + đoạn bài hát 15–40 giây → tạo hàng loạt MV ngắn 9:16 (mỗi biến thể một MV), có thể chạy tự động tới khi ghép xong.
 */
class ShortsController extends Controller
{
    public function index(Request $request): Response
    {
        $bands = $request->user()->virtualBands()->withCount('musicVideos')->latest('id')->get();
        $bands->each->syncImages();

        return Inertia::render('music-videos/shorts', [
            'bands' => $bands->map(fn (VirtualBand $band) => [
                'id' => $band->id, 'name' => $band->name, 'genre' => $band->genre, 'era' => $band->era, 'lore' => $band->lore,
                'style_preset' => $band->style_preset, 'status' => $band->status, 'error' => $band->error, 'music_videos_count' => $band->music_videos_count,
                'members' => collect($band->members ?? [])->map(fn ($m) => [...$m, 'image_url' => Media::url($m['image_path'] ?? null)])->all(),
                'variations' => collect(ShortTemplates::TEMPLATES)->keys()->mapWithKeys(fn ($t) => [$t => ShortTemplates::defaultVariations($t, $band)]),
            ]),
            'templates' => ShortTemplates::TEMPLATES,
            'songs' => $request->user()->audioTracks()->whereNotNull('path')->latest('id')->get(['id', 'name', 'duration_ms']),
            'stylePresets' => collect(Series::MV_STYLE_PRESETS)->map(fn ($p) => $p['label']),
            'drivers' => MusicVideoController::drivers(),
            // Credits mỗi MV ngắn: lên ý tưởng + viết phân cảnh (ảnh / clip Muse tính riêng khi làm).
            'creditsPerVideo' => CreditPricing::aiEstimates()['film_plan'] + CreditPricing::aiEstimates()['film_episode'] * 2,
            'credits' => (int) $request->user()->credit_balance,
        ]);
    }

    /** Tạo ban nhạc ảo: Claude nghĩ ra từ thể loại + thời kỳ, hoặc tự nhập thành viên. */
    public function storeBand(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'mode' => ['required', Rule::in(['ai', 'manual'])],
            'genre' => ['required', 'string', 'max:100'],
            'era' => ['required', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'name' => ['required_if:mode,manual', 'nullable', 'string', 'max:120'],
            'lore' => ['nullable', 'string', 'max:1500'],
            'style_preset' => ['nullable', Rule::in(array_keys(Series::MV_STYLE_PRESETS))],
            'members' => ['required_if:mode,manual', 'array', 'max:6'],
            'members.*.name' => ['required', 'string', 'max:60'],
            'members.*.role' => ['nullable', 'string', 'max:60'],
            'members.*.look' => ['required', 'string', 'max:600'],
            'members.*.look_old' => ['nullable', 'string', 'max:500'],
        ], [], ['genre' => 'thể loại', 'era' => 'thời kỳ', 'name' => 'tên ban nhạc', 'members.*.look' => 'ngoại hình']);
        $blocked = SeriesPlanner::blockedName(($validated['name'] ?? '').' '.($validated['notes'] ?? '').' '.collect($validated['members'] ?? [])->pluck('name')->implode(' '));
        if ($blocked) {
            return back()->withErrors(['name' => "\"{$blocked}\" là tên có bản quyền. Hãy đặt tên riêng."]);
        }
        $ai = $validated['mode'] === 'ai';
        if ($ai && ($message = Credits::shortfall($request->user(), CreditPricing::aiEstimates()['film_plan'], 'tạo ban nhạc ảo'))) {
            return back()->withErrors(['genre' => $message]);
        }
        $band = $request->user()->virtualBands()->create([
            'name' => $ai ? 'Ban nhạc mới' : trim($validated['name']),
            'genre' => trim($validated['genre']),
            'era' => trim($validated['era']),
            'lore' => $validated['lore'] ?? null,
            'style_preset' => $validated['style_preset'] ?? 'cinematic',
            'members' => $ai ? [] : collect($validated['members'])->map(fn ($m) => [
                'name' => trim($m['name']), 'role' => trim((string) ($m['role'] ?? '')), 'look' => trim($m['look']), 'look_old' => trim((string) ($m['look_old'] ?? '')), 'image_path' => null,
            ])->all(),
            'status' => $ai ? 'writing' : 'ready',
        ]);
        if ($ai) {
            GenerateBandJob::dispatch($band, $validated['notes'] ?? null);
        }

        return back()->with('status', $ai ? 'Claude đang nghĩ ra ban nhạc ảo (khoảng 1 phút)...' : "Đã tạo ban nhạc {$band->name}.");
    }

    /** Sửa ban nhạc (tên, câu chuyện, thành viên); ảnh thành viên giữ nguyên theo tên. */
    public function updateBand(Request $request, VirtualBand $band): RedirectResponse
    {
        abort_unless($band->user_id === $request->user()->id, 403);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'genre' => ['required', 'string', 'max:100'],
            'era' => ['required', 'string', 'max:40'],
            'lore' => ['nullable', 'string', 'max:1500'],
            'style_preset' => ['required', Rule::in(array_keys(Series::MV_STYLE_PRESETS))],
            'members' => ['required', 'array', 'min:1', 'max:6'],
            'members.*.name' => ['required', 'string', 'max:60'],
            'members.*.role' => ['nullable', 'string', 'max:60'],
            'members.*.look' => ['required', 'string', 'max:600'],
            'members.*.look_old' => ['nullable', 'string', 'max:500'],
        ]);
        if ($blocked = SeriesPlanner::blockedName($validated['name'].' '.collect($validated['members'])->pluck('name')->implode(' '))) {
            return back()->withErrors(['name' => "\"{$blocked}\" là tên có bản quyền. Hãy đặt tên riêng."]);
        }
        $images = collect($band->members ?? [])->mapWithKeys(fn ($m) => [mb_strtolower($m['name']) => $m['image_path'] ?? null]);
        $band->update([
            ...collect($validated)->except('members')->all(),
            'members' => collect($validated['members'])->map(fn ($m) => [
                'name' => trim($m['name']), 'role' => trim((string) ($m['role'] ?? '')), 'look' => trim($m['look']), 'look_old' => trim((string) ($m['look_old'] ?? '')),
                'image_path' => $images[mb_strtolower(trim($m['name']))] ?? null,
            ])->all(),
        ]);

        return back()->with('status', 'Đã lưu ban nhạc.');
    }

    public function destroyBand(Request $request, VirtualBand $band): RedirectResponse
    {
        abort_unless($band->user_id === $request->user()->id, 403);
        $band->delete();

        return back()->with('status', 'Đã xoá ban nhạc (các MV đã tạo vẫn còn).');
    }

    /** Xem trước tên video, hashtag của các biến thể (chưa tạo gì). */
    public function preview(Request $request): JsonResponse
    {
        [$band, $template, $variations, $track] = $this->input($request);

        return response()->json(['items' => array_map(fn ($v) => collect(ShortTemplates::build($template, $band, $v, $track->name))->only(['title', 'hashtags', 'description'])->all(), $variations)]);
    }

    /** Tạo hàng loạt MV ngắn: mỗi biến thể một MV 9:16 dùng cùng đoạn bài hát, Claude lên ý tưởng; tuỳ chọn chạy tự động tới khi ghép xong. */
    public function batch(Request $request): RedirectResponse
    {
        [$band, $template, $variations, $track, $validated] = $this->input($request, true);
        $start = (int) round(($validated['song_start'] ?? 0) * 1000);
        $length = (int) round(($validated['seconds'] ?? 25) * 1000);
        if ($track->duration_ms !== null && $start + $length > $track->duration_ms) {
            return back()->withErrors(['song_start' => 'Đoạn bài hát vượt quá độ dài bài.']);
        }
        $price = (CreditPricing::aiEstimates()['film_plan'] + CreditPricing::aiEstimates()['film_episode'] * 2) * count($variations);
        if ($message = Credits::shortfall($request->user(), $price, 'tạo '.count($variations).' MV ngắn')) {
            return back()->withErrors(['variations' => $message]);
        }
        $band->syncImages();
        $autopilot = (bool) ($validated['autopilot'] ?? false);
        foreach (array_values($variations) as $i => $variation) {
            $short = ShortTemplates::build($template, $band, $variation, $track->name);
            $series = $request->user()->series()->create([
                'title' => $short['title'],
                'idea' => $short['idea'],
                'format' => 'music_video',
                'audio_track_id' => $track->id,
                'virtual_band_id' => $band->id,
                'song_start_ms' => $start > 0 ? $start : null,
                'song_end_ms' => $start + $length,
                'lyrics' => $track->lyrics,
                'mv_options' => [
                    'lip_sync' => $short['lip_sync'], 'driver' => $validated['driver'], 'style_preset' => $band->style_preset,
                    'template' => $template, 'variation' => $variation,
                    'overlay' => ['title' => $short['title'], 'labels' => true],
                    'post' => ['title' => $short['title'], 'description' => $short['description'], 'hashtags' => $short['hashtags']],
                    'autopilot' => $autopilot,
                ],
                // Thành viên đã có ảnh (từ MV trước của ban): giữ đúng mặt.
                'seed_characters' => collect($short['members'])->filter(fn ($m) => filled($m['image_path'] ?? null))
                    ->map(fn ($m) => ['name' => $m['name'], 'note' => "{$m['role']}. {$m['look']}", 'path' => $m['image_path']])->values()->all() ?: null,
                'seed_episodes' => [['title' => $short['title'], 'idea' => $short['idea'], 'brief' => $short['brief']]],
                'genre' => 'music', 'age_min' => 13, 'age_max' => Series::AGE_MAX, 'audience' => 'YouTube Shorts / TikTok / Reels music fans, international', 'made_for_kids' => false,
                'style' => Series::MV_STYLE_PRESETS[$band->style_preset]['style'] ?? Series::MV_STYLE_PRESETS['cinematic']['style'],
                'aspect_ratio' => '9:16',
                'episode_count' => 1,
                'episode_seconds' => (int) ceil($length / 1000),
                'subtitle_languages' => [],
                'status' => SeriesStatus::Planning,
            ]);
            // Giãn cách để Claude không nhận cùng lúc quá nhiều yêu cầu.
            PlanSeriesJob::dispatch($series)->delay(now()->addSeconds($i * 20));
            if ($autopilot) {
                MusicVideoAutopilotJob::dispatch($series)->delay(now()->addSeconds(60 + $i * 20));
            }
        }

        return to_route('music-videos.index', ['band' => $band->id])->with('status', 'Đã tạo '.count($variations).' MV ngắn cho '.$band->name
            .($autopilot ? ', đang chạy tự động (ý tưởng → phân cảnh → khung hình → clip → ghép).' : '. Mở từng MV để làm tiếp.'));
    }

    /** @return array{0: VirtualBand, 1: string, 2: list<string>, 3: AudioTrack, 4: array} */
    private function input(Request $request, bool $create = false): array
    {
        $validated = $request->validate([
            'band_id' => ['required', 'integer', Rule::exists('virtual_bands', 'id')->where('user_id', $request->user()->id)],
            'template' => ['required', Rule::in(array_keys(ShortTemplates::TEMPLATES))],
            'variations' => ['required', 'string', 'max:2000'],
            'audio_track_id' => ['required', 'integer', Rule::exists('audio_tracks', 'id')->where('user_id', $request->user()->id)->whereNotNull('path')],
            ...($create ? [
                'song_start' => ['nullable', 'numeric', 'min:0'],
                'seconds' => ['required', 'integer', 'min:15', 'max:40'],
                'driver' => ['required', Rule::in(collect(MusicVideoController::drivers())->filter(fn ($d) => $d['available'])->keys()->all())],
                'autopilot' => ['boolean'],
                'confirm' => ['accepted'],
            ] : []),
        ], ['confirm.accepted' => 'Cần xác nhận số MV và Credits trước khi tạo.', 'driver.in' => 'Công cụ tạo ảnh / clip chưa dùng được.'],
            ['variations' => 'biến thể', 'audio_track_id' => 'bài hát']);
        $band = VirtualBand::findOrFail($validated['band_id']);
        $variations = array_slice(array_values(array_filter(array_map('trim', preg_split('/\R/u', $validated['variations']) ?: []))), 0, 10);
        $blocked = SeriesPlanner::blockedName(implode(' ', $variations));
        abort_if($band->status !== 'ready' || $band->members === [], 422, 'Ban nhạc chưa sẵn sàng.');
        if ($variations === [] || $blocked) {
            throw ValidationException::withMessages(['variations' => $blocked ? "\"{$blocked}\" là tên có bản quyền." : 'Nhập ít nhất một biến thể.']);
        }

        return [$band, $validated['template'], $variations, AudioTrack::findOrFail($validated['audio_track_id']), $validated];
    }
}
