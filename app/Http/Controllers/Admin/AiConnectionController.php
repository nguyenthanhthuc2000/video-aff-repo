<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Support\AiKeys;
use App\Support\AiProviders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Kết nối AI: nhập khoá (MiniMax, fal, Claude API) lưu mã hoá, kiểm tra kết nối miễn phí, chọn công cụ cho từng bước kèm chi phí. */
class AiConnectionController extends Controller
{
    public function show(): Response
    {
        $statuses = AiKeys::statuses();

        return Inertia::render('admin/ai-connections', [
            'keys' => collect(AiKeys::KEYS)->map(fn ($key, $provider) => [
                'label' => $key['label'],
                'masked' => AiKeys::masked(AiKeys::get($provider)),
                'source' => AiKeys::source($provider),
                'status' => $statuses[$provider] ?? null,
            ]),
            'minimaxRegion' => config('videoaff.minimax.region'),
            'steps' => collect(AiProviders::STEPS)->map(fn ($label, $step) => [
                'label' => $label,
                'selected' => AiProviders::selected($step),
                'options' => AiProviders::options($step),
            ]),
        ]);
    }

    /** Lưu khoá (để trống = giữ nguyên, tick xoá = bỏ khoá đã nhập, quay về .env). Không bao giờ trả khoá về giao diện. */
    public function updateKeys(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'keys' => ['array'],
            'keys.*' => ['nullable', 'string', 'max:500'],
            'clear' => ['array'],
            'clear.*' => [Rule::in(array_keys(AiKeys::KEYS))],
            'minimax_region' => ['required', Rule::in(['global', 'china'])],
        ]);
        foreach (array_keys(AiKeys::KEYS) as $provider) {
            if (in_array($provider, $validated['clear'] ?? [], true)) {
                AiKeys::put($provider, null);
            } elseif (filled($validated['keys'][$provider] ?? null)) {
                AiKeys::put($provider, $validated['keys'][$provider]);
            }
        }
        AppSetting::put(AiKeys::MINIMAX_REGION, $validated['minimax_region']);
        AiKeys::apply();

        return back()->with('status', 'Đã lưu khoá. Bấm "Kiểm tra kết nối" để thử.');
    }

    public function check(string $provider): RedirectResponse
    {
        abort_unless(isset(AiKeys::KEYS[$provider]), 404);
        $result = AiKeys::check($provider);

        return back()->with('status', AiKeys::KEYS[$provider]['label'].': '.$result['message']);
    }

    public function updateProviders(Request $request): RedirectResponse
    {
        $validated = $request->validate(['providers' => ['required', 'array'], 'providers.*' => ['string', 'max:40']]);
        AiProviders::save($validated['providers']);

        return back()->with('status', 'Đã lưu công cụ cho từng bước.');
    }
}
