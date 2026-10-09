<?php

namespace App\Services\Image;

use RuntimeException;

class MuseBrowserException extends RuntimeException
{
    /** 409: chưa đăng nhập muse.ai. */
    public function needsLogin(): bool
    {
        return $this->getCode() === 409;
    }
}
