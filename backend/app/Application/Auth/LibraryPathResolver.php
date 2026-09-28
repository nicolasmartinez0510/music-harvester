<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Application\Settings\ProviderSettingsResolver;
use App\Models\User;

final readonly class LibraryPathResolver
{
    public function __construct(
        private ProviderSettingsResolver $settings,
    ) {}

    public function effectiveDestination(User $user): string
    {
        if ($user->download_destination === 'server' && ($user->isAdmin() || $user->server_storage_status === 'approved')) {
            return 'server';
        }

        return 'direct';
    }

    public function outputRoot(?User $user, string $destination, ?int $jobId = null): string
    {
        $music = rtrim($this->settings->musicPath(), '/');

        if ($user === null || $destination === 'server') {
            if ($user === null || $user->isAdmin()) {
                return $music;
            }

            return $music.'/'.$user->username;
        }

        $temp = storage_path('app/private/tmp-downloads/'.$user->id);

        return $jobId !== null ? $temp.'/'.$jobId : $temp;
    }

    public function rootForPlaylist(?User $user): string
    {
        if ($user === null) {
            return rtrim($this->settings->musicPath(), '/');
        }

        return $this->outputRoot($user, $this->effectiveDestination($user));
    }
}
