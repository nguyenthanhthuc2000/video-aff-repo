<?php

namespace App\Services\Script;

/** JSON schema cho đầu ra kịch bản (structured outputs). */
class ScriptSchema
{
    public static function schema(): array
    {
        $string = ['type' => 'string'];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['hooks', 'scenes', 'caption', 'hashtags'],
            'properties' => [
                'hooks' => ['type' => 'array', 'items' => $string],
                'scenes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['visual', 'on_screen_text', 'voiceover', 'image_prompt', 'duration_ms'],
                        'properties' => [
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
                'visual' => (string) $scene['visual'],
                'on_screen_text' => mb_substr((string) ($scene['on_screen_text'] ?? ''), 0, 200),
                'voiceover' => (string) ($scene['voiceover'] ?? ''),
                'image_prompt' => (string) $scene['image_prompt'],
                'duration_ms' => max(1000, min(10000, (int) ($scene['duration_ms'] ?? 3000))),
            ];
        }

        return [
            'hooks' => array_values(array_slice(array_map('strval', $data['hooks']), 0, 3)),
            'scenes' => $scenes,
            'caption' => (string) ($data['caption'] ?? ''),
            'hashtags' => array_values(array_map('strval', $data['hashtags'] ?? [])),
        ];
    }
}
