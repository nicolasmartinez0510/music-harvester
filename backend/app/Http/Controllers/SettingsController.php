<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Auth\LibraryPathResolver;
use App\Application\GetSettings\GetSettingsHandler;
use App\Application\UpdateSettings\UpdateSettingsCommand;
use App\Application\UpdateSettings\UpdateSettingsHandler;
use App\Http\Requests\UpdateSettingsRequest;
use App\Http\Resources\SettingsResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SettingsController extends Controller
{
    public function show(Request $request, GetSettingsHandler $handler, LibraryPathResolver $paths): SettingsResource
    {
        return new SettingsResource($this->present($handler->handle(), $request->user(), $paths));
    }

    public function update(
        Request $request,
        UpdateSettingsRequest $form,
        UpdateSettingsHandler $handler,
        LibraryPathResolver $paths,
    ): SettingsResource|JsonResponse {
        try {
            $settings = $handler->handle(new UpdateSettingsCommand($form->validated()));
        } catch (\ValueError $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return new SettingsResource($this->present($settings, $request->user(), $paths));
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function present(array $settings, mixed $user, LibraryPathResolver $paths): array
    {
        if (! $user instanceof User) {
            return $settings;
        }

        $settings['is_admin'] = $user->isAdmin();
        $settings['download_destination'] = $user->download_destination;
        $settings['server_storage_status'] = $user->server_storage_status;
        $settings['effective_download_destination'] = $paths->effectiveDestination($user);
        $settings['library_root'] = $paths->rootForPlaylist($user);

        if (! $user->isAdmin()) {
            $settings['provider_youtube_music_cookies_path'] = null;
            $settings['cookies_path'] = null;
        }

        return $settings;
    }
}
