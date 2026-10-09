<?php

namespace App\Services\Image;

final class GeneratedImage
{
    public function __construct(
        public readonly string $contents,
        public readonly string $extension,
        public readonly string $provider,
        public readonly ?string $requestId = null,
        public readonly ?float $costUsd = null,
    ) {}
}
