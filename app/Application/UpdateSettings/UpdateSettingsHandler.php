<?php

declare(strict_types=1);

namespace App\Application\UpdateSettings;

use App\Application\GetSettings\GetSettingsHandler;
use App\Domain\Music\Contracts\SettingsRepository;
use App\Domain\Music\ValueObjects\AudioFormat;
use App\Infrastructure\Persistence\EloquentSettingsRepository;

final readonly class UpdateSettingsCommand
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(
        public array $values,
    ) {}
}

final readonly class UpdateSettingsHandler
{
    public function __construct(
        private SettingsRepository $settings,
        private GetSettingsHandler $getSettings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(UpdateSettingsCommand $command): array
    {
        foreach ($command->values as $key => $value) {
            if (! in_array($key, EloquentSettingsRepository::KEYS, true)) {
                continue;
            }

            if ($key === 'default_format' && is_string($value)) {
                AudioFormat::from($value);
            }

            if ($key === 'max_concurrency') {
                $value = (string) max(1, (int) $value);
            }

            if ($value === null || $value === '') {
                $this->settings->set($key, null);

                continue;
            }

            $this->settings->set($key, (string) $value);
        }

        return $this->getSettings->handle();
    }
}
