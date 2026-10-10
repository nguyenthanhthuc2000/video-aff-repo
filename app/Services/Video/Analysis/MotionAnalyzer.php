<?php

namespace App\Services\Video\Analysis;

/** Phân tích chuyển động trong video mẫu từ các khung hình (có thời điểm). */
interface MotionAnalyzer
{
    /**
     * @param  list<array{time: float, contents: string}>  $frames  Ảnh JPEG theo thứ tự thời gian.
     * @return array{summary: string, tempo: string, framing: string, steps: list<array{start: float, end: float, motion: string}>}
     *
     * @throws \RuntimeException
     */
    public function analyze(array $frames, float $duration): array;
}
