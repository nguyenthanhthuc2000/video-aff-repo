<?php

namespace App\Services\Film;

use App\Services\Image\AsyncImageGenerator;
use App\Services\Image\GeneratedImage;
use Illuminate\Support\Str;

/** Ảnh giả 16:9 (khi chưa dùng Muse qua trình duyệt, và trong test): xong ngay ở lần hỏi đầu tiên. */
class FakeFilmImageGenerator implements AsyncImageGenerator
{
    public function start(array $referencePaths, string $prompt): string
    {
        return 'VA-'.strtoupper(Str::random(6));
    }

    public function poll(string $code, array $referencePaths): ?GeneratedImage
    {
        $image = imagecreatetruecolor(320, 180);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 214, 102));
        ob_start();
        imagepng($image);

        return new GeneratedImage((string) ob_get_clean(), 'png', 'fake', $code, 0);
    }
}
