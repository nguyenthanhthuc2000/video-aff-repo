<?php

namespace App\Services\Script;

use App\Support\MusePlan;
use App\Support\PromoText;

/** JSON schema cho đầu ra kịch bản (structured outputs). */
class ScriptSchema
{
    public const MAX_HOOKS = 5;

    public static function schema(): array
    {
        $string = ['type' => 'string'];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['hooks', 'hook_themes', 'recommended_hook', 'scenes', 'caption', 'hashtags'],
            'properties' => [
                'hooks' => ['type' => 'array', 'items' => $string],
                'hook_themes' => ['type' => 'array', 'items' => $string],
                'recommended_hook' => ['type' => 'integer'],
                'scenes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['phase', 'visual', 'on_screen_text', 'voiceover', 'image_prompt', 'duration_ms'],
                        'properties' => [
                            // Video biến hình: before = cảnh dùng ảnh "trước"; video thường luôn là after.
                            'phase' => ['type' => 'string', 'enum' => ['before', 'after']],
                            'visual' => $string,
                            'on_screen_text' => $string,
                            'voiceover' => $string,
                            'image_prompt' => $string,
                            'duration_ms' => ['type' => 'integer'],
                        ],
                    ],
                ],
                'caption' => $string,
                'hashtags' => ['type' => 'array', 'items' => $string],
            ],
        ];
    }

    /**
     * Kiểm tra và chuẩn hoá kịch bản trả về.
     *
     * @throws ScriptWriterException
     */
    public static function normalize(mixed $data): array
    {
        if (! is_array($data) || empty($data['hooks']) || empty($data['scenes'])) {
            throw new ScriptWriterException('Kịch bản trả về thiếu hook hoặc cảnh.');
        }

        $scenes = [];
        foreach (array_values($data['scenes']) as $scene) {
            if (! is_array($scene) || blank($scene['visual'] ?? null) || blank($scene['image_prompt'] ?? null)) {
                throw new ScriptWriterException('Kịch bản có cảnh thiếu mô tả hoặc prompt ảnh.');
            }

            $scenes[] = [
                'phase' => ($scene['phase'] ?? 'after') === 'before' ? 'before' : 'after',
                'visual' => (string) $scene['visual'],
                'on_screen_text' => mb_substr(PromoText::strip((string) ($scene['on_screen_text'] ?? '')), 0, 200),
                'voiceover' => PromoText::strip((string) ($scene['voiceover'] ?? '')),
                'image_prompt' => (string) $scene['image_prompt'],
                'duration_ms' => max(1000, min(MusePlan::MAX_SHOT * 1000, (int) ($scene['duration_ms'] ?? 3000))),
            ];
        }

        $hooks = array_values(array_slice(array_map(fn ($hook) => PromoText::strip((string) $hook), $data['hooks']), 0, self::MAX_HOOKS));
        $themes = array_values(array_map('strval', (array) ($data['hook_themes'] ?? [])));
        $recommended = (int) ($data['recommended_hook'] ?? 0);

        return [
            'hooks' => $hooks,
            // Chủ đề từng hook (bản cũ / claude.ai có thể thiếu: để trống).
            'hook_themes' => array_map(fn (int $i) => mb_substr($themes[$i] ?? '', 0, 60), array_keys($hooks)),
            'recommended_hook' => $recommended >= 0 && $recommended < count($hooks) ? $recommended : 0,
            'scenes' => $scenes,
            // Không nhắc ưu đãi / khuyến mãi (lọc cả khi Claude vẫn viết).
            'caption' => PromoText::strip((string) ($data['caption'] ?? '')),
            'hashtags' => PromoText::stripHashtags(array_values(array_map('strval', $data['hashtags'] ?? []))),
        ];
    }
}
