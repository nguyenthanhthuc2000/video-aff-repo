<?php

namespace App\Services\Social;

use RuntimeException;
use Throwable;

/** Lỗi khi kết nối / đăng video lên mạng xã hội. */
class SocialPostException extends RuntimeException
{
    /** @param  bool  $retryable  lỗi tạm thời (mạng, 5xx, 429): job nên thử lại */
    public function __construct(string $message, public readonly bool $retryable = false, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
