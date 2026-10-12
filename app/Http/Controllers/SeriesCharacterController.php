<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateCharacterImageJob;
use App\Models\Series;
use App\Models\SeriesCharacter;
use App\Models\SeriesCharacterImage;
use App\Services\Audio\Tts;
use App\Services\Film\CharacterPrompt;
use App\Services\Film\Voices;
use App\Support\CreditPricing;
use App\Support\Credits;
use App\Support\Media;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/** Nhân vật của bộ phim: thêm / xoá, thư viện ảnh (Muse vẽ hoặc tải lên), chọn ảnh chính, chốt nhân vật. */
class SeriesCharacterController extends Controller
{
    public function show(Series $series, SeriesCharacter $character): Response
    {
        $this->authorize('view', $series);
        $characters = $series->characters()->get(['id', 'name', 'main_image_path']);

        return Inertia::render('series/character', [
            'series' => ['id' => $series->id, 'title' => $series->title, 'style' => $series->style],
            'character' => [
                'id' => $character->id,
                'name' => $character->name,
                'role' => $character->role,
                'age' => $character->age,
                'personality' => $character->personality,
                'appearance' => $character->appearance,
                'voice_type' => $character->voice['type'] ?? null,
                'voice' => $character->voice['voice'] ?? null,
                'voice_sample_url' => Media::url(self::samplePath($character)),
                'main_image_path' => $character->main_image_path,
                'main_image_url' => Media::url($character->main_image_path),
                'locked' => $character->locked_at !== null,
                'images' => $character->images()->get()->map(fn (SeriesCharacterImage $image) => [
                    'id' => $image->id,
                    'kind' => $image->kind,
                    'kind_label' => SeriesCharacterImage::KINDS[$image->kind] ?? $image->kind,
                    'source' => $image->source,
                    'status' => $image->status,
                    'url' => Media::url($image->path),
                    'is_main' => $image->path !== null && $image->path === $character->main_image_path,
                    'prompt' => $image->prompt,
                    'error' => $image->error,
                    'created_at' => $image->created_at,
                ]),
            ],
            'characters' => $characters->map(fn (SeriesCharacter $c) => ['id' => $c->id, 'name' => $c->name, 'image_url' => Media::url($c->main_image_path)]),
            'kinds' => SeriesCharacterImage::KINDS,
            'roles' => SeriesCharacter::ROLES,
            'voiceTypes' => collect(Voices::TYPES)->map(fn ($voice) => $voice['label']),
            'voices' => Voices::options(),
            // Giọng các nhân vật khác đang dùng (báo trùng giọng).
            'usedVoices' => $series->characters()->whereKeyNot($character->id)->get(['name', 'voice'])->mapWithKeys(fn (SeriesCharacter $c) => [$c->voice['voice'] ?? '' => $c->name])->filter(),
            'imageCredits' => CreditPricing::settings()['steps']['image'],
        ]);
    }

    public function store(Request $request, Series $series): RedirectResponse
    {
        $this->authorize('update', $series);
        $validated = $request->validate(self::rules(), [], self::attributes());

        $character = $series->characters()->create([
            ...collect($validated)->except('voice_type')->all(),
            'voice' => Voices::assign($series, $validated['voice_type']),
            'position' => (int) $series->characters()->max('position') + 1,
        ]);

        return to_route('series.characters.show', [$series, $character])->with('status', "Đã thêm nhân vật {$character->name}. Bấm Muse vẽ hoặc tải ảnh lên.");
    }

    public function update(Request $request, Series $series, SeriesCharacter $character): RedirectResponse
    {
        $this->authorize('update', $series);
        $validated = $request->validate(self::rules(), [], self::attributes());

        $character->update([
            ...collect($validated)->except(['voice_type', 'voice', 'appearance'])->all(),
            // Đã chốt: giữ ngoại hình (ảnh chính đã vẽ theo mô tả đó).
            ...($character->locked_at === null ? ['appearance' => $validated['appearance']] : []),
            // Chọn đúng giọng thì dùng giọng đó; chỉ đổi loại giọng thì tự chọn giọng chưa ai dùng; không đổi gì thì giữ nguyên.
            'voice' => match (true) {
                filled($validated['voice'] ?? null) && $validated['voice'] !== ($character->voice['voice'] ?? null) => Voices::option($validated['voice']),
                ($character->voice['type'] ?? null) === $validated['voice_type'] => $character->voice,
                default => Voices::assign($series, $validated['voice_type'], $character),
            },
        ]);

        return back()->with('status', 'Đã lưu nhân vật.');
    }

    public function destroy(Series $series, SeriesCharacter $character): RedirectResponse
    {
        $this->authorize('update', $series);
        if ($character->images()->where('status', 'pending')->exists()) {
            return back()->with('status', 'Muse đang vẽ ảnh của nhân vật này, đợi xong rồi xoá.');
        }
        foreach ($character->images as $image) {
            Media::delete($image->path);
        }
        $name = $character->name;
        $character->delete();

        return to_route('series.show', $series)->with('status', "Đã xoá nhân vật {$name}.");
    }

    /** Muse vẽ một ảnh nhân vật (tốn Credits như một ảnh cảnh). */
    public function generate(Request $request, Series $series, SeriesCharacter $character): RedirectResponse
    {
        $this->authorize('update', $series);
        $validated = $request->validate([
            'kind' => ['required', Rule::in(array_keys(SeriesCharacterImage::KINDS))],
            'notes' => ['nullable', 'required_if:kind,custom', 'string', 'max:500'],
        ], ['notes.required_if' => 'Mô tả tư thế / hành động muốn vẽ.'], ['notes' => 'mô tả thêm']);

        if ($validated['kind'] !== 'main' && $character->main_image_path === null) {
            return back()->withErrors(['kind' => 'Cần có ảnh chính trước: Muse vẽ các loại ảnh khác dựa theo ảnh chính để giữ đúng nhân vật.']);
        }
        if ($character->images()->where('status', 'pending')->count() >= 3) {
            return back()->withErrors(['kind' => 'Đang có 3 ảnh chờ Muse vẽ, đợi xong rồi vẽ thêm.']);
        }
        if ($message = Credits::shortfall($request->user(), CreditPricing::settings()['steps']['image'], 'vẽ nhân vật')) {
            return back()->withErrors(['kind' => $message]);
        }

        $image = $character->images()->create([
            'kind' => $validated['kind'],
            'source' => 'muse',
            'status' => 'pending',
            'prompt' => CharacterPrompt::build($character, $validated['kind'], $validated['notes'] ?? null, $validated['kind'] !== 'main'),
        ]);
        GenerateCharacterImageJob::dispatch($image);

        return back()->with('status', 'Muse đang vẽ, khoảng 1–2 phút...');
    }

    public function upload(Request $request, Series $series, SeriesCharacter $character): RedirectResponse
    {
        $this->authorize('update', $series);
        $request->validate([
            'photos' => ['required', 'array', 'min:1', 'max:10'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ], ['photos.required' => 'Chọn ít nhất 1 ảnh.'], ['photos' => 'ảnh', 'photos.*' => 'ảnh']);

        foreach ($request->file('photos') as $photo) {
            $path = Media::store($photo, "series/{$series->id}/characters");
            $character->images()->create(['kind' => 'main', 'source' => 'upload', 'status' => 'ready', 'path' => $path]);
            if ($character->main_image_path === null) {
                $character->update(['main_image_path' => $path]);
            }
        }

        return back()->with('status', 'Đã tải ảnh lên.');
    }

    public function setMain(Series $series, SeriesCharacter $character, SeriesCharacterImage $image): RedirectResponse
    {
        $this->authorize('update', $series);
        abort_unless($image->series_character_id === $character->id && $image->path !== null, 404);
        if ($character->locked_at !== null) {
            return back()->with('status', 'Nhân vật đã chốt: bỏ chốt rồi mới đổi ảnh chính.');
        }
        $character->update(['main_image_path' => $image->path]);

        return back()->with('status', 'Đã đặt làm ảnh chính.');
    }

    public function destroyImage(Series $series, SeriesCharacter $character, SeriesCharacterImage $image): RedirectResponse
    {
        $this->authorize('update', $series);
        abort_unless($image->series_character_id === $character->id, 404);
        if ($image->status === 'pending') {
            return back()->with('status', 'Ảnh đang vẽ, đợi xong rồi xoá.');
        }
        if ($image->path !== null && $image->path === $character->main_image_path) {
            if ($character->locked_at !== null) {
                return back()->with('status', 'Không xoá được ảnh chính của nhân vật đã chốt.');
            }
            $character->update(['main_image_path' => null]);
        }
        Media::delete($image->path);
        $image->delete();

        return back()->with('status', 'Đã xoá ảnh.');
    }

    /** Chốt: ảnh chính + ngoại hình thành tham chiếu cố định cho mọi shot. Bỏ chốt để sửa. */
    public function lock(Request $request, Series $series, SeriesCharacter $character): RedirectResponse
    {
        $this->authorize('update', $series);
        $lock = $request->boolean('locked');
        if ($lock && $character->main_image_path === null) {
            return back()->with('status', 'Cần chọn ảnh chính trước khi chốt nhân vật.');
        }
        $character->update(['locked_at' => $lock ? now() : null]);

        return back()->with('status', $lock ? "Đã chốt {$character->name}." : "Đã bỏ chốt {$character->name}.");
    }

    /** Nghe thử giọng của nhân vật (Kokoro chạy trên máy, không tốn Credits); cùng giọng thì dùng lại bản đã đọc. */
    public function voiceSample(Series $series, SeriesCharacter $character, Tts $tts): RedirectResponse
    {
        $this->authorize('view', $series);
        $path = self::samplePath($character, exists: false);
        if ($path === null) {
            return back()->with('status', 'Nhân vật chưa có giọng.');
        }
        if (! Media::disk()->exists($path)) {
            try {
                $voice = $character->voice;
                $result = $tts->speak([['text' => Voices::sample($character), 'voice' => $voice['voice'], 'pitch' => (float) $voice['pitch'], 'speed' => (float) $voice['speed']]]);
            } catch (RuntimeException $e) {
                return back()->with('status', $e->getMessage());
            }
            Media::disk()->put($path, $result['contents']);
        }

        return back()->with('status', 'Đã đọc câu nghe thử, bấm phát để nghe.');
    }

    /** File nghe thử theo đúng giọng + thông số hiện tại (đổi giọng thì đọc bản mới). */
    private static function samplePath(SeriesCharacter $character, bool $exists = true): ?string
    {
        $voice = $character->voice;
        if (empty($voice['voice'])) {
            return null;
        }
        $path = "series/{$character->series_id}/voices/{$character->id}-{$voice['voice']}-".substr(sha1(json_encode([$voice, Voices::sample($character)])), 0, 8).'.wav';

        return ! $exists || Media::disk()->exists($path) ? $path : null;
    }

    private static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'role' => ['required', Rule::in(array_keys(SeriesCharacter::ROLES))],
            'age' => ['nullable', 'integer', 'min:0', 'max:120'],
            'personality' => ['nullable', 'string', 'max:1000'],
            'appearance' => ['required', 'string', 'max:1500'],
            'voice_type' => ['required', Rule::in(array_keys(Voices::TYPES))],
            'voice' => ['nullable', Rule::in(array_column(Voices::options(), 'value'))],
        ];
    }

    private static function attributes(): array
    {
        return ['name' => 'tên', 'appearance' => 'ngoại hình', 'personality' => 'tính cách', 'age' => 'tuổi'];
    }
}
