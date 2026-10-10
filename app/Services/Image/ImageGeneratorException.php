<?php

namespace App\Services\Image;

use RuntimeException;

class ImageGeneratorException extends RuntimeException
{
    /** Lỗi tạm thời (429, 5xx, mạng): job nên thử lại. */
    public bool $retryable = false;

    /** Dịch vụ đang chạy đủ số yêu cầu song song: chưa gửi được, chờ lượt (không tính là lần thử lỗi). */
    public bool $busy = false;

    /** Muse từ chối nội dung; $refusal là lời giải thích của Muse. */
    public bool $rejected = false;

    public ?string $refusal = null;

    public static function retryable(string $message, ?\Throwable $previous = null): self
    {
        $e = new self($message, 0, $previous);
        $e->retryable = true;

        return $e;
    }

    public static function rejected(string $message, string $refusal): self
    {
        $e = new self($message);
        $e->rejected = true;
        $e->refusal = $refusal;

        return $e;
    }

    public static function busy(string $message, ?\Throwable $previous = null): self
    {
        $e = self::retryable($message, $previous);
        $e->busy = true;

        return $e;
    }
}
