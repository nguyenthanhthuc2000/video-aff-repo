<?php

namespace App\Enums;

/** Loại ảnh người mẫu: sau (đã makeup) hoặc trước (mặt mộc, tóc rối, đồ cũ) cho video biến hình. */
enum FashionModelKind: string
{
    case After = 'after';
    case Before = 'before';

    public function label(): string
    {
        return match ($this) {
            self::After => 'Sau',
            self::Before => 'Trước',
        };
    }

    /** Tiền tố tên mặc định: "Mẫu 1" (sau), "Mẫu trước 1" (trước). */
    public function defaultNamePrefix(): string
    {
        return match ($this) {
            self::After => 'Mẫu',
            self::Before => 'Mẫu trước',
        };
    }
}
