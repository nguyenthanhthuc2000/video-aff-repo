<?php

namespace App\Services\Image\Prompt;

use App\Models\Scene;

/** Viết prompt ảnh mới cho cảnh (bị muse.ai từ chối, hoặc người dùng muốn ảnh khác): trang nhã, giữ bối cảnh và sản phẩm. */
interface ScenePromptRewriter
{
    /** @throws \RuntimeException khi không viết lại được (job dùng cách lọc câu thay thế). */
    public function rewrite(Scene $scene, string $reason): string;
}
