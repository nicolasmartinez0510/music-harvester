<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

final class OwnedResource
{
    public static function visible(mixed $ownerId, User $user): bool
    {
        if ($ownerId === null || $ownerId === '') {
            return $user->isAdmin();
        }

        return (int) $ownerId === (int) $user->id;
    }
}
