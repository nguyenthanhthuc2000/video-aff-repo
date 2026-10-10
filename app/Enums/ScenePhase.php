<?php

namespace App\Enums;

/** Cảnh của video biến hình: trước (ảnh "trước") hay sau khi biến hình (ảnh "sau" mặc sản phẩm). */
enum ScenePhase: string
{
    case Before = 'before';
    case After = 'after';
}
