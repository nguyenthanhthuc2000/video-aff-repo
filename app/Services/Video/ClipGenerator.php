<?php

namespace App\Services\Video;

use App\Services\Image\ImageGeneratorException;

/** Biến ảnh cảnh thành clip chuyển động ngắn (gửi rồi hỏi lại). */
interface ClipGenerator
{
    /** @throws ImageGeneratorException */
    public function start(string $imagePath, string $prompt): string;

    /**
     * @return array{contents: string, extension: string}|null null nếu chưa xong
     *
     * @throws ImageGeneratorException
     */
    public function poll(string $code): ?array;
}
