<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateCharacterPortraitJob;
use App\Models\CharacterPortrait;
use App\Support\CreditPricing;
use App\Support\Credits;
use App\Support\DragonBall;
use App\Support\Media;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Thư viện nhân vật Ngọc Rồng: Muse vẽ mỗi nhân vật với các biểu cảm (manga trắng đen), dùng làm ảnh mẫu / ảnh cảnh cho Shorts chủ đề. */
class CharacterLibraryController extends Controller
{
    public function index(Request $request): Response
    {
        $portraits = $request->user()->characterPortraits()->get()->keyBy(fn (CharacterPortrait $p) => "{$p->character}:{$p->expression}");

        return Inertia::render('character-library/index', [
            'characters' => collect(DragonBall::CHARACTERS)->map(fn ($character, $key) => [
                'key' => $key,
                'name' => $character[0],
                'look' => $character[1],
                'suggested' => $character[2],
                'portraits' => collect(DragonBall::EXPRESSIONS)->keys()->mapWithKeys(function ($expression) use ($portraits, $key) {
                    $portrait = $portraits->get("{$key}:{$expression}");

                    return [$expression => $portrait ? [
                        'status' => $portrait->status,
                        'source' => $portrait->source,
                        'image_url' => Media::url($portrait->image_path),
                        'error' => $portrait->error,
                    ] : null];
                }),
            ])->values(),
            'expressions' => collect(DragonBall::EXPRESSIONS)->map(fn ($e) => $e[0]),
            'price' => CreditPricing::settings()['steps']['image'],
            'busy' => $portraits->contains(fn (CharacterPortrait $p) => $p->status === 'pending'),
        ]);
    }

    /**
     * Muse vẽ: một ảnh (character + expression), cả một nhân vật (character) hoặc cả thư viện (không truyền gì).
     * Vẽ nhiều thì chỉ vẽ các biểu cảm gợi ý chưa có ảnh; vẽ một ảnh thì vẽ lại cả khi đã có.
     */
    public function draw(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'character' => ['nullable', Rule::in(array_keys(DragonBall::CHARACTERS))],
            'expression' => ['nullable', Rule::in(array_keys(DragonBall::EXPRESSIONS))],
        ]);
        $user = $request->user();
        $existing = $user->characterPortraits()->get()->keyBy(fn (CharacterPortrait $p) => "{$p->character}:{$p->expression}");

        $targets = [];
        if (filled($validated['character'] ?? null) && filled($validated['expression'] ?? null)) {
            $targets[] = [$validated['character'], $validated['expression']];
        } else {
            $keys = filled($validated['character'] ?? null) ? [$validated['character']] : array_keys(DragonBall::CHARACTERS);
            foreach ($keys as $key) {
                foreach (DragonBall::CHARACTERS[$key][2] as $expression) {
                    $portrait = $existing->get("{$key}:{$expression}");
                    if ($portrait === null || $portrait->status === 'failed') {
                        $targets[] = [$key, $expression];
                    }
                }
            }
        }
        // Ảnh đang vẽ thì bỏ qua.
        $targets = array_values(array_filter($targets, fn ($t) => ($existing->get("{$t[0]}:{$t[1]}")?->status) !== 'pending'));
        if ($targets === []) {
            return back()->with('status', 'Không có ảnh nào cần vẽ.');
        }
        if ($message = Credits::shortfall($user, count($targets) * CreditPricing::settings()['steps']['image'], 'vẽ ảnh')) {
            return back()->with('status', $message);
        }

        // Vẽ mặt thường (E1) trước để các biểu cảm khác bám theo.
        usort($targets, fn ($a, $b) => [$a[0], $a[1] !== 'E1'] <=> [$b[0], $b[1] !== 'E1']);
        foreach ($targets as [$character, $expression]) {
            $portrait = $user->characterPortraits()->updateOrCreate(
                ['character' => $character, 'expression' => $expression],
                ['status' => 'pending', 'request_id' => null, 'error' => null],
            );
            GenerateCharacterPortraitJob::dispatch($portrait);
        }

        return back()->with('status', 'Muse đang vẽ '.count($targets).' ảnh...');
    }

    public function upload(Request $request, string $character, string $expression): RedirectResponse
    {
        abort_unless(isset(DragonBall::CHARACTERS[$character], DragonBall::EXPRESSIONS[$expression]), 404);
        $request->validate(['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:15360']], [], ['image' => 'ảnh']);

        $portrait = $request->user()->characterPortraits()->firstOrNew(['character' => $character, 'expression' => $expression]);
        $old = $portrait->image_path;
        $portrait->fill([
            'status' => 'ready',
            'source' => 'upload',
            'request_id' => null,
            'error' => null,
            'image_path' => Media::store($request->file('image'), "character-portraits/{$request->user()->id}"),
        ])->save();
        Media::delete($old);

        return back()->with('status', 'Đã lưu ảnh '.DragonBall::CHARACTERS[$character][0].': '.DragonBall::EXPRESSIONS[$expression][0].'.');
    }
}
