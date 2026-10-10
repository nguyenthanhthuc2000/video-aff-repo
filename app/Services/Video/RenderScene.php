<?php

namespace App\Services\Video;

final class RenderScene
{
    public function __construct(
        /** Đường dẫn ảnh trên máy (không phải disk media). */
        public readonly string $imagePath,
        public readonly int $durationMs,
        /** Chữ lớn trên màn hình. */
        public readonly string $title = '',
        /** Phụ đề phía dưới (lời đọc). */
        public readonly string $subtitle = '',
        /** Clip chuyển động trên máy; có thì dùng thay ảnh (ảnh vẫn dùng cho cảnh kết). */
        public readonly ?string $clipPath = null,
        /** Clip có tiếng (dùng khi giữ tiếng clip). */
        public readonly bool $clipHasAudio = false,
        /** Bỏ đoạn đầu clip (ms): clip AI hay mở bằng vài khung đứng hình, cắt đi thì chỗ nối giữa các cảnh rơi vào lúc đang chuyển động. */
        public readonly int $clipOffsetMs = 0,
    ) {}
}
