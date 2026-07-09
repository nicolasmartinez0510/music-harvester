<?php

declare(strict_types=1);

namespace App\Domain\Music\ValueObjects;

enum AudioFormat: string
{
    case Mp3_320 = 'mp3_320';
    case M4a = 'm4a';

    public function extension(): string
    {
        return match ($this) {
            self::Mp3_320 => 'mp3',
            self::M4a => 'm4a',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Mp3_320 => 'MP3 320kbps',
            self::M4a => 'M4A (AAC)',
        };
    }
}
