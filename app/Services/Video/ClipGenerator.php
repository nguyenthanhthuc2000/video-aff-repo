<?php

namespace App\Services\Video;

use App\Services\Image\ImageGeneratorException;

/** Biến ảnh cảnh thành clip chuyển động ngắn (gửi rồi hỏi lại). */
interface ClipGenerator
{
    /**
     * @param  ?string  $referenceVideoPath  Video mẫu (disk media) để sao chép chuyển động, gửi kèm sau ảnh.
     * @param  ?string  $audioPath  Đoạn nhạc (disk media) để người mẫu hát nhép theo, gửi kèm cuối.
     *
     * @throws ImageGeneratorException
     */
    public function start(string $imagePath, string $prompt, ?string $referenceVideoPath = null, ?string $audioPath = null): string;

    /**
     * @return array{contents: string, extension: string}|null null nếu chưa xong
     *
     * @throws ImageGeneratorException
     */
    public function poll(string $code): ?array;
}
