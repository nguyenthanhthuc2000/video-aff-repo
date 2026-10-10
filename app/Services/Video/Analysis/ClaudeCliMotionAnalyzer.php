<?php

namespace App\Services\Video\Analysis;

use App\Services\Claude\ClaudeCli;
use RuntimeException;

/** Phân tích chuyển động bằng Claude Code CLI (gói Claude Pro/Max). */
class ClaudeCliMotionAnalyzer implements MotionAnalyzer
{
    public function __construct(private readonly ClaudeCli $cli) {}

    public function analyze(array $frames, float $duration): array
    {
        $content = [['type' => 'text', 'text' => MotionAnalysisPrompt::intro($duration, count($frames))]];
        foreach ($frames as $frame) {
            $content[] = ['type' => 'text', 'text' => sprintf('t=%.1fs', $frame['time'])];
            $content[] = ClaudeCli::imageBlock($frame['contents'], 'image/jpeg') ?? throw new RuntimeException('Khung hình không hợp lệ.');
        }

        return MotionAnalysisPrompt::normalize($this->cli->json(MotionAnalysisPrompt::SYSTEM, $content, MotionAnalysisPrompt::schema(), 'motion_analysis'));
    }
}
