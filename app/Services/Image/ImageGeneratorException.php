<?php

namespace App\Services\Image;

use RuntimeException;

class ImageGeneratorException extends RuntimeException
{
    /** Lỗi tạm thời (429, 5xx, mạng): job nên thử lại. */
    public bool $retryable = false;

    public static function retryable(string $message, ?\Throwable $previous = null): self
    {
        $e = new self($message, 0, $previous);
        $e->retryable = true;

        return $e;
    }
}
