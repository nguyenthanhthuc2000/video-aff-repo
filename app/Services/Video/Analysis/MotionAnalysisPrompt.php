<?php

namespace App\Services\Video\Analysis;

/** Prompt và schema phân tích chuyển động, dùng chung cho Claude Code CLI và Claude API. */
final class MotionAnalysisPrompt
{
    public const SYSTEM = <<<'PROMPT'
    Bạn phân tích chuyển động của người trong một video ngắn để người khác làm lại y chang (điệu nhảy trend, cách tạo dáng...).
    Đầu vào là các khung hình chụp đều đặn, mỗi khung có ghi thời điểm (giây).

    Trả về:
    - summary: 1–2 câu tiếng Việt tóm tắt chuyển động (để người dùng đọc).
    - tempo: tiếng Anh, nhịp độ (slow / medium / fast) và số lần lặp lại nếu có.
    - framing: tiếng Anh, khung hình và máy quay (full body / half body, góc, máy đứng yên hay di chuyển, người tiến / lùi).
    - steps: dòng thời gian liên tục phủ hết video, mỗi bước 0.5–2 giây: start, end (giây) và motion (tiếng Anh, chi tiết:
      chân bước hướng nào, hông, vai, tay trái / tay phải làm gì, đầu, hướng nhìn, xoay người bao nhiêu độ, biểu cảm).

    Chỉ tả chuyển động và máy quay. Không tả quần áo, ngoại hình, bối cảnh, chữ hay logo. Không đoán những gì không thấy trong khung hình.
    PROMPT;

    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['summary', 'tempo', 'framing', 'steps'],
            'properties' => [
                'summary' => ['type' => 'string'],
                'tempo' => ['type' => 'string'],
                'framing' => ['type' => 'string'],
                'steps' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['start', 'end', 'motion'],
                        'properties' => [
                            'start' => ['type' => 'number'],
                            'end' => ['type' => 'number'],
                            'motion' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function intro(float $duration, int $count): string
    {
        return sprintf('Video dài %.1f giây, %d khung hình bên dưới. Phân tích chuyển động theo đúng định dạng.', $duration, $count);
    }

    /** @return array{summary: string, tempo: string, framing: string, steps: list<array{start: float, end: float, motion: string}>} */
    public static function normalize(mixed $data): array
    {
        if (! is_array($data) || empty($data['steps']) || ! is_array($data['steps'])) {
            throw new \RuntimeException('Claude không trả về dòng thời gian chuyển động.');
        }

        $steps = array_values(array_filter(array_map(fn ($step) => is_array($step) && filled($step['motion'] ?? null) ? [
            'start' => round((float) ($step['start'] ?? 0), 1),
            'end' => round((float) ($step['end'] ?? 0), 1),
            'motion' => mb_substr(trim((string) $step['motion']), 0, 400),
        ] : null, $data['steps'])));

        return [
            'summary' => mb_substr(trim((string) ($data['summary'] ?? '')), 0, 600),
            'tempo' => mb_substr(trim((string) ($data['tempo'] ?? '')), 0, 200),
            'framing' => mb_substr(trim((string) ($data['framing'] ?? '')), 0, 300),
            'steps' => array_slice($steps, 0, 30),
        ];
    }
}
