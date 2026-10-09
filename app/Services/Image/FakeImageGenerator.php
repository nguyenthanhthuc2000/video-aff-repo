<?php

namespace App\Services\Image;

use App\Support\Media;

/** Trả lại ảnh người mẫu làm ảnh cảnh. Dùng khi chưa có khoá và trong test. */
class FakeImageGenerator implements ImageGenerator
{
    public function generate(array $referencePaths, string $prompt): GeneratedImage
    {
        $path = $referencePaths[0] ?? null;
        $contents = $path ? Media::disk()->get($path) : null;

        if ($contents === null) {
            throw new ImageGeneratorException('Không đọc được ảnh người mẫu.');
        }

        return new GeneratedImage(
            contents: $contents,
            extension: pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg',
            provider: 'fake',
            costUsd: 0,
        );
    }
}
