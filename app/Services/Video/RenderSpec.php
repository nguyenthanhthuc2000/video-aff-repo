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
        /** false = không chèn chữ nào (hook, chữ trên cảnh, phụ đề) và bỏ cảnh kết. */
        public readonly bool $withText = true,
        /** Chuyển cảnh mờ dần (ms); 0 = cắt thẳng như video quay điện thoại (đỡ giống trình chiếu AI). */
        public readonly int $transitionMs = 0,
        /** Hạt nhiễu nhẹ như máy quay điện thoại, làm ảnh / clip AI bớt "mịn nhựa" và đồng màu giữa các cảnh. */
        public readonly bool $grain = true,
    ) {}

    /** Tổng thời lượng video, gồm cảnh kết. */
    public function durationMs(): int
    {
        return array_sum(array_map(fn (RenderScene $scene) => $scene->durationMs, $this->scenes)) + ($this->hasEndCard() ? $this->endCardMs : 0);
    }

    /** Cảnh kết là chữ kêu gọi mua trên nền ảnh mờ, nên không chèn chữ thì bỏ luôn. */
    public function hasEndCard(): bool
    {
        return $this->withText && $this->endCardMs > 0;
    }
}
