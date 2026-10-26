<?php

namespace App\Http\Controllers;

use App\Models\LoreFact;
use App\Support\DragonBall;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Kho tư liệu: các chi tiết đã duyệt, dùng lại khi research chủ đề mới (đỡ tìm lại, đỡ tốn lượt Claude). */
class LoreFactController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate(['character' => ['nullable', Rule::in(array_keys(DragonBall::CHARACTERS))], 'q' => ['nullable', 'string', 'max:100']]);
        $facts = $request->user()->loreFacts()->latest('id')
            ->when($validated['character'] ?? null, fn ($q, $key) => $q->whereJsonContains('characters', $key))
            // Tìm trong nội dung, arc / tập, nguồn và tên nhân vật (gõ "Vegeta" ra mọi chi tiết gắn Vegeta).
            ->when(trim((string) ($validated['q'] ?? '')), fn ($q, $text) => $q->where(function ($q) use ($text) {
                $q->where('text', 'like', "%{$text}%")->orWhere('era', 'like', "%{$text}%")->orWhere('source_title', 'like', "%{$text}%");
                foreach (DragonBall::charactersIn($text) ?: array_filter([DragonBall::keyFor($text)]) as $key) {
                    $q->orWhereJsonContains('characters', $key);
                }
            }))
            ->limit(300)->get();

        return Inertia::render('lore-facts/index', [
            'facts' => $facts->map(fn (LoreFact $fact) => [
                'id' => $fact->id,
                'kind' => $fact->kind,
                'era' => $fact->era,
                'text' => $fact->text,
                'source_url' => $fact->source_url,
                'source_title' => $fact->source_title,
                'characters' => collect($fact->characters ?? [])->map(fn ($key) => DragonBall::CHARACTERS[$key][0] ?? $key)->values(),
            ]),
            'filters' => ['character' => $validated['character'] ?? '', 'q' => $validated['q'] ?? ''],
            'kinds' => LoreFact::KINDS,
            'characters' => collect(DragonBall::CHARACTERS)->map(fn ($c) => $c[0]),
            'total' => $request->user()->loreFacts()->count(),
        ]);
    }

    public function update(Request $request, LoreFact $fact): RedirectResponse
    {
        abort_unless($fact->user_id === $request->user()->id, 403);
        $validated = $request->validate([
            'kind' => ['required', Rule::in(array_keys(LoreFact::KINDS))],
            'era' => ['nullable', 'string', 'max:120'],
            'text' => ['required', 'string', 'max:600'],
            'source_url' => ['nullable', 'url:http,https', 'max:500'],
        ], [], ['text' => 'nội dung', 'source_url' => 'link nguồn']);
        $fact->update($validated);

        return back()->with('status', 'Đã lưu tư liệu.');
    }

    public function destroy(Request $request, LoreFact $fact): RedirectResponse
    {
        abort_unless($fact->user_id === $request->user()->id, 403);
        $fact->delete();

        return back()->with('status', 'Đã xoá tư liệu.');
    }
}
