<?php

declare(strict_types=1);

namespace App\Domain\Music\Exceptions;

use DomainException;

final class UnsupportedMusicUrlException extends DomainException
{
    public static function forUrl(string $url): self
    {
        return new self('No music provider supports this URL: '.$url);
    }
}
