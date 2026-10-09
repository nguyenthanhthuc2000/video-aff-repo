<?php

namespace App\Enums;

enum VideoProjectStatus: string
{
    case Draft = 'draft';
    case Scripting = 'scripting';
    case ScriptReady = 'script_ready';
    case Imaging = 'imaging';
    case Reviewing = 'reviewing';
    case Rendering = 'rendering';
    case Done = 'done';
    case Failed = 'failed';

    /** Đang có job chạy nền; giao diện nên tự làm mới. */
    public function isBusy(): bool
    {
        return in_array($this, [self::Scripting, self::Imaging, self::Rendering], true);
    }
}
