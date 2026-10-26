<?php

namespace App\Http\Controllers;

use App\Services\Audio\Tts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

/** Bảng cách đọc tên tiếng Anh cho giọng Việt: lưu / bỏ cách đọc riêng, nghe thử một cách đọc. */
class PronunciationController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'word' => ['required', 'string', 'max:80'],
            'say' => ['required', 'string', 'max:120'],
        ]);
        $request->user()->pronunciations()->updateOrCreate(
            ['word' => mb_strtolower(trim($validated['word']))],
            ['say' => trim($validated['say'])],
        );

        return back()->with('status', 'Đã lưu cách đọc "'.trim($validated['word']).'". Dựng lại video để nghe giọng mới.');
    }

    /** Bỏ cách đọc riêng: tên có sẵn quay về cách đọc mặc định. */
    public function destroy(Request $request): RedirectResponse
    {
        $validated = $request->validate(['word' => ['required', 'string', 'max:80']]);
        $request->user()->pronunciations()->where('word', mb_strtolower(trim($validated['word'])))->delete();

        return back()->with('status', 'Đã bỏ cách đọc riêng.');
    }

    /** Đọc thử đúng chữ người dùng gõ bằng giọng Việt, trả file wav. */
    public function preview(Request $request, Tts $tts): Response
    {
        $validated = $request->validate(['say' => ['required', 'string', 'max:200']]);
        try {
            $voice = $tts->speak([['text' => $validated['say'], 'voice' => '', 'pitch' => 1.0, 'speed' => 1.0, 'lang' => 'vi']], 0.1, 0.1, 0.2);
        } catch (RuntimeException $e) {
            return response($e->getMessage(), 503, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return response($voice['contents'], 200, ['Content-Type' => 'audio/wav', 'Cache-Control' => 'no-store']);
    }
}
