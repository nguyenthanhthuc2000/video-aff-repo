<?php

namespace App\Http\Controllers;

use App\Jobs\ComposeSongDraftJob;
use App\Jobs\GenerateSongJob;
use App\Models\AudioTrack;
use App\Models\SongDraft;
use App\Services\Audio\YoutubeReference;
use App\Services\Film\LyricsOverlap;
use App\Services\Film\SongWriter;
use App\Services\Video\VideoProbe;
use App\Support\CreditPricing;
use App\Support\Credits;
use App\Support\Media;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Sáng tác bài mới lấy cảm hứng từ link YouTube (trang Tạo MV): phân tích bài tham khảo, Claude viết lời + phong cách nhạc mới,
 * sửa / viết lại, tạo nhạc (tự làm ở công cụ ngoài rồi tải lên, hoặc qua API), bài mới vào thư viện Âm thanh để làm MV.
 */
class SongDraftController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048', fn ($attribute, $value, $fail) => YoutubeReference::isYoutube($value) ? null : $fail('Chỉ nhận link YouTube (youtube.com hoặc youtu.be).')],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['url.required' => 'Dán link YouTube của bài tham khảo.'], ['url' => 'link YouTube', 'notes' => 'hướng sáng tác']);
        if ($message = Credits::shortfall($request->user(), CreditPricing::aiEstimates()['film_plan'] * 2, 'sáng tác bài mới')) {
            return back()->withErrors(['url' => $message]);
        }

        $draft = $request->user()->songDrafts()->create([
            'source_url' => trim($validated['url']),
            'notes' => filled($validated['notes'] ?? null) ? trim($validated['notes']) : null,
            'status' => 'fetching',
        ]);
        ComposeSongDraftJob::dispatch($draft);

        return back()->with('status', 'Đang đọc bài tham khảo, Claude phân tích rồi sáng tác bài mới (khoảng 1–3 phút)...');
    }

    /** Claude viết lại bài mới (giữ hồ sơ tham khảo), có thể kèm hướng sáng tác mới. */
    public function rewrite(Request $request, SongDraft $songDraft): RedirectResponse
    {
        $this->authorizeDraft($request, $songDraft);
        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);
        if ($songDraft->isBusy() || $songDraft->profile === null) {
            return back()->with('status', 'Bài đang được xử lý hoặc chưa phân tích xong bài tham khảo.');
        }
        if ($message = Credits::shortfall($request->user(), CreditPricing::aiEstimates()['film_plan'], 'viết lại bài hát')) {
            return back()->withErrors(['notes' => $message]);
        }
        $songDraft->update(['notes' => filled($validated['notes'] ?? null) ? trim($validated['notes']) : $songDraft->notes, 'status' => 'writing', 'error' => null]);
        ComposeSongDraftJob::dispatch($songDraft);

        return back()->with('status', 'Claude đang viết lại bài mới...');
    }

    /** Lưu lời / tên / prompt phong cách đã sửa; kiểm tra lại mức trùng lời với bài tham khảo. */
    public function update(Request $request, SongDraft $songDraft): RedirectResponse
    {
        $this->authorizeDraft($request, $songDraft);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'style_prompt' => ['required', 'string', 'max:300'],
            'lyrics' => ['required', 'string', 'max:20000'],
        ], [], ['title' => 'tên bài', 'style_prompt' => 'phong cách nhạc', 'lyrics' => 'lời bài hát']);
        $overlap = LyricsOverlap::check($validated['lyrics'], SongWriter::referenceLyrics($songDraft));
        $songDraft->update([
            'song' => [...($songDraft->song ?? []), 'title' => trim($validated['title']), 'style_prompt' => SongWriter::cleanStyle($validated['style_prompt'], $songDraft), 'lyrics' => trim($validated['lyrics'])],
            'overlap' => [...$overlap, 'too_similar' => LyricsOverlap::tooSimilar($overlap)],
        ]);

        return back()->with('status', LyricsOverlap::tooSimilar($overlap) ? 'Đã lưu, nhưng lời còn giống bài tham khảo: sửa các câu được đánh dấu.' : 'Đã lưu bài mới.');
    }

    /** Tải lên bài hát đã tạo ở công cụ ngoài (Suno, Udio...) từ lời + phong cách của bài mới. */
    public function upload(Request $request, SongDraft $songDraft): RedirectResponse
    {
        $this->authorizeDraft($request, $songDraft);
        $request->validate([
            'song' => ['required', 'file', 'mimes:mp3,wav,m4a', 'max:'.config('videoaff.audio_max_kb')],
            'rights' => ['accepted'],
        ], ['rights.accepted' => 'Cần xác nhận bạn có quyền dùng bài nhạc đã tạo (theo điều khoản của công cụ tạo nhạc).'], ['song' => 'file bài hát']);
        if ($songDraft->song === null) {
            return back()->with('status', 'Chưa có bài mới.');
        }
        $file = $request->file('song');
        $track = self::attach($songDraft, (string) file_get_contents($file->getRealPath()), $file->getClientOriginalExtension() ?: 'mp3', $file->getRealPath());

        return back()->with('status', "Đã thêm bài mới \"{$track->name}\" vào thư viện, đang lấy lời theo thời gian.");
    }

    /** Tạo nhạc qua API (trả phí): chỉ gửi lời + prompt phong cách của bài mới. */
    public function generate(Request $request, SongDraft $songDraft): RedirectResponse
    {
        $this->authorizeDraft($request, $songDraft);
        $validated = $request->validate(['provider' => ['required', Rule::in(collect(self::providers())->filter(fn ($p) => $p['available'] && $p['api'])->keys()->all())]],
            ['provider.in' => 'Công cụ tạo nhạc này chưa dùng được.']);
        if ($songDraft->song === null || $songDraft->isBusy()) {
            return back()->with('status', 'Chưa có bài mới hoặc đang tạo nhạc.');
        }
        $songDraft->update(['music_provider' => $validated['provider'], 'music_status' => 'pending', 'music_request_id' => null, 'music_error' => null]);
        GenerateSongJob::dispatch($songDraft);

        return back()->with('status', 'Đang tạo bài hát (vài phút)...');
    }

    /**
     * Lưu bài mới vào thư viện Âm thanh: lời, ngôn ngữ, phân tích có sẵn (thể loại, nhịp, không khí, chủ đề), rồi lấy lời theo thời gian.
     */
    public static function attach(SongDraft $draft, string $contents, string $extension, ?string $localPath): AudioTrack
    {
        $song = $draft->song;
        $path = "audio/{$draft->user_id}/".Str::random(40).'.'.strtolower($extension);
        Media::disk()->put($path, $contents);
        $duration = $localPath ? (app(VideoProbe::class)->probe($localPath)['duration_ms'] ?? null) : null;
        $track = $draft->user->audioTracks()->create([
            'name' => Str::limit($song['title'] ?: 'Bài hát mới', 200, ''),
            'path' => $path,
            'mime' => $extension === 'wav' ? 'audio/wav' : 'audio/mpeg',
            'size_bytes' => strlen($contents),
            'duration_ms' => $duration,
            'rights_confirmed_at' => now(),
            'lyrics' => $song['lyrics'],
            'language' => $song['language'] ?: null,
            'song_info' => [
                'source' => 'song_draft',
                'song_draft_id' => $draft->id,
                'analysis' => [
                    'title' => $song['title'], 'artist' => '', 'genre' => $song['genre'], 'tempo_bpm' => $song['tempo_bpm'],
                    'tempo_feel' => $draft->profile['tempo_feel'] ?? '', 'mood' => $draft->profile['mood'] ?? '', 'structure' => [],
                    'themes' => $draft->profile['themes'] ?? [], 'lyrics' => $song['lyrics'], 'summary' => $song['concept'],
                ],
                'analysis_status' => 'ready',
                // Ý tưởng MV và ca sĩ Claude viết cùng bài mới: điền sẵn ở trang Tạo MV.
                'mv_idea' => $song['mv_idea'],
                'singer' => $song['singer'],
            ],
        ]);
        $draft->update(['audio_track_id' => $track->id, 'music_status' => 'done', 'music_error' => null]);
        $track->analyzeVocals();

        return $track;
    }

    /**
     * Cách tạo nhạc cho bài mới.
     *
     * @return array<string, array{label: string, available: bool, api: bool, note: string}>
     */
    public static function providers(): array
    {
        return [
            'manual' => [
                'label' => 'Tự tạo ở công cụ ngoài (Suno, Udio...) rồi tải lên',
                'available' => true,
                'api' => false,
                'note' => 'Copy phong cách nhạc + lời sang công cụ tạo nhạc, tải bài về rồi tải lên đây. Kiểm tra điều khoản dùng thương mại của công cụ.',
            ],
            'fal' => [
                'label' => 'fal API (trả phí theo lượt)',
                'available' => filled(config('videoaff.fal.key')),
                'api' => true,
                'note' => filled(config('videoaff.fal.key'))
                    ? 'Model '.config('videoaff.fal.music_endpoint').'. Chưa chạy thử với tài khoản thật.'
                    : 'Chưa đặt FAL_KEY trong .env.',
            ],
        ];
    }

    private function authorizeDraft(Request $request, SongDraft $draft): void
    {
        abort_unless($draft->user_id === $request->user()->id, 403);
    }
}
