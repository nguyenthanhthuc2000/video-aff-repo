<?php

namespace App\Services\Video;

final class RenderSpec
{
    /**
     * @param  list<RenderScene>  $scenes
     */
    public function __construct(
        public readonly array $scenes,
        public readonly string $hook,
        public readonly string $outputPath,
        public readonly string $endCardTitle = 'Xem màu, size trong giỏ hàng',
        public readonly string $endCardSubtitle = '',
        public readonly int $endCardMs = 2500,
        public readonly ?string $musicPath = null,
    ) {}

    /** Tổng thời lượng video, gồm cảnh kết. */
    public function durationMs(): int
    {
        return array_sum(array_map(fn (RenderScene $scene) => $scene->durationMs, $this->scenes)) + $this->endCardMs;
    }
}
