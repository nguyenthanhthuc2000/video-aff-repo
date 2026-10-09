<?php

namespace App\Services\Video;

interface VideoRenderer
{
    /**
     * Ghép video MP4 vào $spec->outputPath.
     *
     * @param  (callable(int): void)|null  $onProgress  nhận phần trăm đã ghép (0–100)
     *
     * @throws VideoRenderException
     */
    public function render(RenderSpec $spec, ?callable $onProgress = null): void;
}
