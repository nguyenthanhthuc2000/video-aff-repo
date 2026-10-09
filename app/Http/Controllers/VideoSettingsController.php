<?php

namespace App\Http\Controllers;

use App\Support\VideoPreset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Menu Cài đặt video: độ dài video (số cảnh, số giây) dùng khi Claude viết kịch bản. */
class VideoSettingsController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('video-settings/index', [
            'settings' => VideoPreset::settings(),
            'presets' => array_values(array_map(
                fn (string $key, array $preset) => ['key' => $key, ...$preset, 'recommended' => false],
                array_keys(VideoPreset::PRESETS),
                VideoPreset::PRESETS,
            )),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $scenes = max(1, (int) $request->input('scene_count', 1));

        $validated = $request->validate([
            'mode' => ['required', Rule::in(['auto', 'fixed'])],
            'scene_count' => ['required', 'integer', 'min:'.VideoPreset::MIN_SCENES, 'max:'.VideoPreset::MAX_SCENES],
            'target_seconds' => [
                'required', 'integer', 'min:'.VideoPreset::MIN_SECONDS, 'max:'.VideoPreset::MAX_SECONDS,
                function (string $attribute, mixed $value, \Closure $fail) use ($scenes) {
                    if ($value / $scenes < 2 || $value / $scenes > 8) {
                        $fail('Mỗi cảnh nên dài 2–8 giây: chỉnh lại số cảnh hoặc thời lượng.');
                    }
                },
            ],
        ], [], ['scene_count' => 'số cảnh', 'target_seconds' => 'thời lượng']);

        VideoPreset::saveSettings($validated);

        return back()->with('status', 'Đã lưu cài đặt video. Áp dụng khi viết (hoặc viết lại) kịch bản.');
    }
}
