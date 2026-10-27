<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessMangaScanJob;
use App\Models\MangaPanel;
use App\Models\MangaScan;
use App\Services\Manga\PanelTagger;
use App\Support\DragonBall;
use App\Support\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use ZipArchive;

/** Kho khung manga: tải trang truyện (ảnh hoặc zip), máy cắt thành khung, Claude ghi nhân vật / nội dung; video truyện lấy khung từ đây. */
class MangaPanelController extends Controller
{
    /** Số trang tối đa mỗi lần tải (kể cả trong zip). */
    public const MAX_PAGES = 120;

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'character' => ['nullable', Rule::in(array_keys(DragonBall::CHARACTERS))],
            'chapter' => ['nullable', 'string', 'max:120'],
            'q' => ['nullable', 'string', 'max:100'],
            'all' => ['nullable', 'boolean'],
        ]);
        $user = $request->user();
        $panels = $user->mangaPanels()->with('scan:id,chapter,name')->latest('manga_scan_id')->orderBy('position')
            ->when(! ($validated['all'] ?? false), fn ($q) => $q->where('useful', true))
            ->when($validated['character'] ?? null, fn ($q, $key) => $q->whereJsonContains('characters', $key))
            ->when($validated['chapter'] ?? null, fn ($q, $chapter) => $q->whereHas('scan', fn ($q) => $q->where('chapter', $chapter)))
            ->when(trim((string) ($validated['q'] ?? '')), fn ($q, $text) => $q->where(function ($q) use ($text) {
                $q->where('description', 'like', "%{$text}%")->orWhereHas('scan', fn ($q) => $q->where('chapter', 'like', "%{$text}%"));
                foreach (DragonBall::charactersIn($text) ?: array_filter([DragonBall::keyFor($text)]) as $key) {
                    $q->orWhereJsonContains('characters', $key);
                }
            }))
            ->limit(400)->get();

        return Inertia::render('manga-panels/index', [
            'panels' => $panels->map(fn (MangaPanel $panel) => self::present($panel)),
            'filters' => ['character' => $validated['character'] ?? '', 'chapter' => $validated['chapter'] ?? '', 'q' => $validated['q'] ?? '', 'all' => (bool) ($validated['all'] ?? false)],
            'characters' => collect(DragonBall::CHARACTERS)->map(fn ($c) => $c[0]),
            'chapters' => $user->mangaScans()->whereNotNull('chapter')->distinct()->orderBy('chapter')->pluck('chapter'),
            'processing' => $user->mangaScans()->where('status', 'processing')->count(),
            'problems' => $user->mangaScans()->whereNotNull('error')->latest('id')->limit(20)->get(['id', 'name', 'chapter', 'status', 'error']),
            'total' => $user->mangaPanels()->count(),
            'pages' => $user->mangaScans()->count(),
            'max_pages' => self::MAX_PAGES,
        ]);
    }

    /** Tìm khung cho ô chọn khung ở bước Ảnh (JSON). */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $text = trim((string) ($validated['q'] ?? ''));
        $panels = $request->user()->mangaPanels()->with('scan:id,chapter,name')->where('useful', true)
            ->when($text !== '', fn ($q) => $q->where(function ($q) use ($text) {
                $q->where('description', 'like', "%{$text}%")->orWhereHas('scan', fn ($q) => $q->where('chapter', 'like', "%{$text}%"));
                foreach (DragonBall::charactersIn($text) ?: array_filter([DragonBall::keyFor($text)]) as $key) {
                    $q->orWhereJsonContains('characters', $key);
                }
            }))
            ->latest('id')->limit(60)->get();

        return response()->json(['panels' => $panels->map(fn (MangaPanel $panel) => self::present($panel))]);
    }

    /** @return array<string, mixed> */
    public static function present(MangaPanel $panel): array
    {
        return [
            'id' => $panel->id,
            'url' => Media::url($panel->image_path),
            'width' => $panel->width,
            'height' => $panel->height,
            'scan_id' => $panel->manga_scan_id,
            'position' => $panel->position,
            'chapter' => $panel->scan?->chapter,
            'page' => $panel->scan?->name,
            'characters' => collect($panel->characters ?? [])->map(fn ($key) => DragonBall::CHARACTERS[$key][0] ?? $key)->values(),
            'description' => $panel->description,
            'mood' => $panel->mood,
            'useful' => $panel->useful,
            'tagged' => $panel->tagged_at !== null,
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'pages' => ['required', 'array', 'max:'.self::MAX_PAGES],
            'pages.*' => ['file', 'mimes:jpg,jpeg,png,webp,zip', 'max:204800'],
            'chapter' => ['nullable', 'string', 'max:120'],
        ], [], ['pages' => 'trang truyện', 'pages.*' => 'trang truyện', 'chapter' => 'chương']);
        $chapter = trim((string) $request->input('chapter')) ?: null;
        $count = 0;
        foreach ($request->file('pages') as $file) {
            foreach (self::images($file) as [$name, $contents, $ext]) {
                if ($count >= self::MAX_PAGES) {
                    break 2;
                }
                $path = 'manga/'.$request->user()->id.'/pages/'.substr(md5($contents), 0, 12).'.'.$ext;
                Media::disk()->put($path, $contents);
                $scan = $request->user()->mangaScans()->create(['chapter' => $chapter, 'name' => mb_substr($name, 0, 250), 'image_path' => $path, 'status' => 'processing']);
                ProcessMangaScanJob::dispatch($scan);
                $count++;
            }
        }
        if ($count === 0) {
            return back()->withErrors(['pages' => 'Không có ảnh trang truyện nào (jpg, png, webp) trong file đã chọn.']);
        }

        return back()->with('status', "Đã nhận {$count} trang. Máy đang cắt khung và Claude đang xem từng khung, xong trang nào hiện trang đó.");
    }

    /**
     * Ảnh trang truyện trong file tải lên: chính file ảnh, hoặc các ảnh trong zip (theo tên file, bỏ thư mục hệ thống của macOS).
     *
     * @return iterable<array{0: string, 1: string, 2: string}> [tên, nội dung, đuôi]
     */
    private static function images(UploadedFile $file): iterable
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if ($ext !== 'zip') {
            yield [$file->getClientOriginalName(), (string) file_get_contents($file->getRealPath()), $ext === 'jpeg' ? 'jpg' : $ext];

            return;
        }
        $zip = new ZipArchive;
        if ($zip->open($file->getRealPath()) !== true) {
            return;
        }
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('/\.(jpe?g|png|webp)$/i', $name) && ! str_contains($name, '__MACOSX') && ! str_starts_with(basename($name), '.')) {
                $names[] = $name;
            }
        }
        natcasesort($names);
        foreach (array_slice($names, 0, self::MAX_PAGES) as $name) {
            $contents = $zip->getFromName($name);
            if ($contents !== false && @getimagesizefromstring($contents)) {
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                yield [basename($name), $contents, $ext === 'jpeg' ? 'jpg' : $ext];
            }
        }
        $zip->close();
    }

    /** Sửa nhân vật / mô tả / cảm xúc của khung, hoặc ẩn khung không dùng được. */
    public function update(Request $request, MangaPanel $panel): RedirectResponse
    {
        abort_unless($panel->user_id === $request->user()->id, 403);
        $validated = $request->validate([
            'characters' => ['nullable', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:500'],
            'mood' => ['nullable', Rule::in(PanelTagger::MOODS)],
            'useful' => ['boolean'],
        ]);
        $keys = array_values(array_unique(array_filter(array_map(fn ($name) => DragonBall::keyFor(trim($name)), explode(',', (string) ($validated['characters'] ?? ''))))));
        $panel->update([
            'characters' => $keys,
            'description' => $validated['description'] ?? null,
            'mood' => $validated['mood'] ?? null,
            'useful' => $validated['useful'] ?? $panel->useful,
        ]);

        return back()->with('status', 'Đã lưu khung.');
    }

    public function destroy(Request $request, MangaPanel $panel): RedirectResponse
    {
        abort_unless($panel->user_id === $request->user()->id, 403);
        $panel->image_path !== $panel->scan?->image_path && Media::delete($panel->image_path);
        $panel->delete();

        return back()->with('status', 'Đã xoá khung.');
    }

    /** Cắt lại trang (khi cắt sai) hoặc chỉ cho Claude xem lại. */
    public function reprocess(Request $request, MangaScan $scan): RedirectResponse
    {
        abort_unless($scan->user_id === $request->user()->id, 403);
        $recut = $request->boolean('recut');
        $scan->update(['status' => 'processing', 'error' => null]);
        ProcessMangaScanJob::dispatch($scan, $recut);

        return back()->with('status', $recut ? 'Đang cắt lại trang.' : 'Claude đang xem lại các khung.');
    }

    /** Dùng nguyên trang làm một khung (trang cắt hỏng, hoặc muốn lia cả trang). */
    public function whole(Request $request, MangaScan $scan): RedirectResponse
    {
        abort_unless($scan->user_id === $request->user()->id, 403);
        $size = @getimagesizefromstring((string) Media::disk()->get($scan->image_path)) ?: [0, 0];
        foreach ($scan->panels()->get() as $old) {
            $old->image_path !== $scan->image_path && Media::delete($old->image_path);
            $old->delete();
        }
        $scan->panels()->create(['user_id' => $scan->user_id, 'position' => 1, 'image_path' => $scan->image_path, 'width' => $size[0], 'height' => $size[1]]);
        $scan->update(['status' => 'processing', 'error' => null]);
        ProcessMangaScanJob::dispatch($scan);

        return back()->with('status', 'Trang này giờ là một khung nguyên trang.');
    }

    public function destroyScan(Request $request, MangaScan $scan): RedirectResponse
    {
        abort_unless($scan->user_id === $request->user()->id, 403);
        foreach ($scan->panels()->get() as $panel) {
            $panel->image_path !== $scan->image_path && Media::delete($panel->image_path);
        }
        Media::delete($scan->image_path);
        $scan->delete();

        return back()->with('status', 'Đã xoá trang và các khung của nó.');
    }
}
