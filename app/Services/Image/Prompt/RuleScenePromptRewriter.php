<?php

namespace App\Services\Image\Prompt;

use App\Models\Scene;

/**
 * Không có Claude (chế độ web, hoặc Claude lỗi): bỏ các câu có từ gợi cảm / nhấn vào cơ thể,
 * thêm yêu cầu tư thế kín đáo. Cảnh cận chi tiết chuyển sang chụp trên manơcanh.
 */
class RuleScenePromptRewriter implements ScenePromptRewriter
{
    private const RISKY = '/\b(sexy|seductive|sensual|alluring|provocative|revealing|sheer|see-through|transparent|cleavage|bust|chest|breast|hips?|waist|thighs?|legs?|skin|bare|slit|neckline|keyhole|low[- ]angle|bodice|curves?|figure-hugging|body)\b/i';

    public function rewrite(Scene $scene, string $reason): string
    {
        $sentences = preg_split('/(?<=[.!?])\s+/u', trim($scene->image_prompt)) ?: [];
        $kept = array_values(array_filter($sentences, fn (string $sentence) => ! preg_match(self::RISKY, $sentence)));
        $closeUp = (bool) preg_match('/\b(close-?up|macro|detail)\b/i', $scene->image_prompt);

        $base = $kept[0] ?? 'A bright, tidy everyday setting with soft natural light.';
        $extra = $closeUp
            ? 'Show the garment on a mannequin or neatly on a hanger, no person, soft even light, focus on the fabric texture and color.'
            : 'The woman wears the exact garment from the reference, standing or walking naturally with a relaxed, modest pose, full-body, eye-level camera.';

        return trim(implode(' ', array_slice($kept, 0, 2)) ?: $base).' '.$extra.' Vertical 9:16.';
    }
}
