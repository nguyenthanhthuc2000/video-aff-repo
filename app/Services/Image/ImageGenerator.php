<?php

namespace App\Services\Image;

interface ImageGenerator
{
    /**
     * Tạo một ảnh cảnh từ các ảnh tham chiếu (ảnh người mẫu đứng đầu, sau đó là ảnh sản phẩm).
     *
     * @param  list<string>  $referencePaths  Đường dẫn trên disk media.
     *
     * @throws ImageGeneratorException
     */
    public function generate(array $referencePaths, string $prompt): GeneratedImage;
}
