<?php

namespace App\Enums;

enum RenderStatus: string
{
    case Pending = 'pending';
    case Done = 'done';
    case Failed = 'failed';
}
