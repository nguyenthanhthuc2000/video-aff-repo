<?php

namespace App\Services\Video\Analysis;

/** Dùng trong test và khi chưa có Claude: trả dòng thời gian mẫu theo số khung hình. */
class FakeMotionAnalyzer implements MotionAnalyzer
{
    public function analyze(array $frames, float $duration): array
    {
        return [
            'summary' => 'Bước sang hai bên theo nhịp, vẫy tay rồi tạo dáng.',
            'tempo' => 'medium',
            'framing' => 'full body, static camera',
            'steps' => [
                ['start' => 0.0, 'end' => round($duration / 2, 1), 'motion' => 'steps left and right on the beat, arms swinging'],
                ['start' => round($duration / 2, 1), 'end' => round($duration, 1), 'motion' => 'waves the right hand, then poses with a smile'],
            ],
        ];
    }
}
