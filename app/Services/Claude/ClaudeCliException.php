<?php

namespace App\Services\Claude;

use RuntimeException;

class ClaudeCliException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
