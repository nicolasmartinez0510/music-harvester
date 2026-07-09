<?php

declare(strict_types=1);

namespace App\Domain\Music\ValueObjects;

final readonly class DownloadResult
{
    public function __construct(
        public bool $success,
        public ?string $destinationPath = null,
        public ?string $error = null,
    ) {}

    public static function ok(string $destinationPath): self
    {
        return new self(success: true, destinationPath: $destinationPath);
    }

    public static function failed(string $error): self
    {
        return new self(success: false, error: $error);
    }
}
