<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\GetSettings\GetSettingsHandler;
use App\Application\UpdateSettings\UpdateSettingsCommand;
use App\Application\UpdateSettings\UpdateSettingsHandler;
use App\Http\Requests\UpdateSettingsRequest;
use App\Http\Resources\SettingsResource;
use Illuminate\Http\JsonResponse;

final class SettingsController extends Controller
{
    public function show(GetSettingsHandler $handler): SettingsResource
    {
        return new SettingsResource($handler->handle());
    }

    public function update(
        UpdateSettingsRequest $request,
        UpdateSettingsHandler $handler,
    ): SettingsResource|JsonResponse {
        try {
            $settings = $handler->handle(new UpdateSettingsCommand($request->validated()));
        } catch (\ValueError $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return new SettingsResource($settings);
    }
}
