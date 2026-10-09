<?php

namespace App\Enums;

enum SceneImageStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Failed = 'failed';
}
