<?php

namespace App\Enums;

enum VideoType: string
{
    case Standard = 'standard';
    /** Biến hình (glow-up): mở đầu bằng ảnh "trước" (đồ cũ, mặt mộc, tóc rối), sau đó makeup mặc sản phẩm. */
    case Transformation = 'transformation';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Video thường',
            self::Transformation => 'Biến hình',
        };
    }
}
