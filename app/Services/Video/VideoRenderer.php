<?php

namespace App\Services\Video;

interface VideoRenderer
{
    /**
     * Ghép video MP4 vào $spec->outputPath.
     *
     * @throws VideoRenderException
     */
    public function render(RenderSpec $spec): void;
}
