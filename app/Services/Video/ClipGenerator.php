<?php

namespace App\Services\Video;

use App\Services\Image\ImageGeneratorException;

/** Biến ảnh cảnh thành clip chuyển động ngắn (gửi rồi hỏi lại). */
interface ClipGenerator
{
    /**
     * @param  ?string  $referenceVideoPath  Video mẫu (disk media) để sao chép chuyển động, gửi kèm sau ảnh.
     *
     * @throws ImageGeneratorException
     */
    public function start(string $imagePath, string $prompt, ?string $referenceVideoPath = null): string;

    /**
     * @return array{contents: string, extension: string}|null null nếu chưa xong
     *
     * @throws ImageGeneratorException
     */
    public function poll(string $code): ?array;
}
