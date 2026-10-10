<?php

namespace App\Support;

use App\Models\AppSetting;

/** Chế độ xử lý (menu Cài đặt video): auto = dự án mới tự chạy ngầm mọi bước; manual = duyệt thủ công từng bước. */
final class Workflow
{
    public static function mode(): string
    {
        return AppSetting::get(AppSetting::WORKFLOW) === 'manual' ? 'manual' : 'auto';
    }

    public static function save(string $mode): void
    {
        AppSetting::put(AppSetting::WORKFLOW, $mode === 'manual' ? 'manual' : 'auto');
    }

    public static function isAuto(): bool
    {
        return self::mode() === 'auto';
    }
}
