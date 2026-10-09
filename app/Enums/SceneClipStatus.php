<?php

namespace App\Enums;

enum SceneClipStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
}
