<?php

namespace App\Services\Image;

use RuntimeException;

class MuseBrowserException extends RuntimeException
{
    /** 409: chưa đăng nhập muse.ai. */
    /** Đang chạy đủ số yêu cầu song song (mỗi yêu cầu một tab). */
    public function busy(): bool
    {
        return $this->getCode() === 429;
    }

    public function needsLogin(): bool
    {
        return $this->getCode() === 409;
    }
}
