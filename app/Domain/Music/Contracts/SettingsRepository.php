<?php

declare(strict_types=1);

namespace App\Domain\Music\Contracts;

interface SettingsRepository
{
    public function get(string $key, ?string $default = null): ?string;

    public function set(string $key, ?string $value): void;

    /**
     * @return array<string, string|null>
     */
    public function all(): array;
}
