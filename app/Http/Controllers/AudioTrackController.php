<?php

namespace App\Http\Controllers;

use App\Models\AudioTrack;
use App\Services\Audio\RemoteAudio;
use App\Services\Audio\RemoteAudioException;
use App\Services\Video\VideoProbe;
use App\Support\Media;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Menu Âm thanh: thư viện nhạc nền (tải file lên, hoặc nhập link nhạc). */
class AudioTrackController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('audio-tracks/index', [
            'audioTracks' => $request->user()->audioTracks()->withCount('videoProjects')->latest()->get()
                ->map(fn (AudioTrack $track) => [...self::present($track), 'used_count' => $track->video_projects_count]),
            'maxMb' => (int) (config('videoaff.audio_max_kb') / 1024),
        ]);
    }

    /** Tải file lên, hoặc nhập link: link file nhạc thì tải về; link trang web (TikTok, YouTube...) thì chỉ lưu link. */
    public function store(Request $request, VideoProbe $probe): RedirectResponse
    {
        $validated = $request->validate([
            'audio' => ['nullable', 'required_without:source_url', 'file', 'mimes:mp3,m4a,aac,wav,ogg', 'max:'.config('videoaff.audio_max_kb')],
            'source_url' => ['nullable', 'required_without:audio', 'url', 'max:2048'],
            'name' => ['nullable', 'string', 'max:255'],
            'rights' => ['accepted'],
        ], [
            'audio.required_without' => 'Chọn file nhạc hoặc nhập link nhạc.',
            'source_url.required_without' => 'Chọn file nhạc hoặc nhập link nhạc.',
            'rights.accepted' => 'Cần xác nhận bạn có quyền dùng nhạc này trong video bán hàng.',
        ], ['audio' => 'file nhạc', 'source_url' => 'link nhạc', 'name' => 'tên']);

        $user = $request->user();
        $directory = "audio/{$user->id}";
        $file = $request->file('audio');
        $url = $validated['source_url'] ?? null;
        $data = ['source_url' => $url, 'rights_confirmed_at' => now()];
        $localPath = null;

        if ($file) {
            $data += ['path' => Media::store($file, $directory), 'mime' => $file->getMimeType(), 'size_bytes' => $file->getSize()];
            $localPath = $file->getRealPath();
            $defaultName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        } else {
            try {
                $download = RemoteAudio::download($url, config('videoaff.audio_max_kb') * 1024);
            } catch (RemoteAudioException $e) {
                throw ValidationException::withMessages(['source_url' => $e->getMessage()]);
            }
            if ($download) {
                $path = "{$directory}/".Str::random(40).".{$download['extension']}";
                Media::disk()->put($path, $download['contents']);
                $data += ['path' => $path, 'mime' => $download['mime'], 'size_bytes' => strlen($download['contents'])];
                $localPath = tempnam(sys_get_temp_dir(), 'audio');
                file_put_contents($localPath, $download['contents']);
            }
            $defaultName = Str::limit(basename((string) parse_url($url, PHP_URL_PATH)) ?: (string) parse_url($url, PHP_URL_HOST), 200, '');
        }

        $track = $user->audioTracks()->create([...$data, 'name' => ($validated['name'] ?? null) ?: ($defaultName ?: 'Nhạc')]);

        if ($localPath) {
            $track->update(['duration_ms' => $probe->probe($localPath)['duration_ms'] ?? null]);
            if (! $file) {
                @unlink($localPath);
            }
        }

        return back()->with('status', $track->isPlayable()
            ? "Đã thêm nhạc \"{$track->name}\"."
            : "Đã lưu link \"{$track->name}\". Link này không phải file nhạc nên chỉ để tham khảo, không ghép vào video được: hãy tải file nhạc lên.");
    }

    public function update(Request $request, AudioTrack $audioTrack): RedirectResponse
    {
        $this->authorize('update', $audioTrack);

        $audioTrack->update($request->validate(['name' => ['required', 'string', 'max:255']], [], ['name' => 'tên']));

        return back()->with('status', 'Đã đổi tên nhạc.');
    }

    public function destroy(AudioTrack $audioTrack): RedirectResponse
    {
        $this->authorize('delete', $audioTrack);

        if ($audioTrack->videoProjects()->exists()) {
            return back()->withErrors(['audio' => 'Nhạc đang được dùng trong dự án video, đổi nhạc của dự án trước khi xoá.']);
        }

        $audioTrack->delete();
        Media::delete($audioTrack->path);

        return back()->with('status', "Đã xoá nhạc \"{$audioTrack->name}\".");
    }

    /** @return array<string, mixed> */
    public static function present(AudioTrack $track): array
    {
        return [
            'id' => $track->id,
            'name' => $track->name,
            'url' => Media::url($track->path),
            'source_url' => $track->source_url,
            'playable' => $track->isPlayable(),
            'size_bytes' => $track->size_bytes,
            'duration_ms' => $track->duration_ms,
        ];
    }
}
