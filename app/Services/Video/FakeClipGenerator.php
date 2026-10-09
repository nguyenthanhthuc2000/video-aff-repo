<?php

namespace App\Services\Video;

/** Dùng trong test: xong ngay, trả nội dung giả. */
class FakeClipGenerator implements ClipGenerator
{
    public function start(string $imagePath, string $prompt): string
    {
        return 'VA-FAKE00';
    }

    public function poll(string $code): ?array
    {
        return ['contents' => 'fake-clip', 'extension' => 'mp4'];
    }
}
