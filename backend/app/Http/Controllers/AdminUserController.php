<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\File;

final class AdminUserController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $users = User::query()->orderBy('id')->get();

        return UserResource::collection($users);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        $target = User::query()->find($id);

        if ($target === null) {
            return response()->json(['message' => 'Usuario no encontrado.'], 404);
        }

        if ($actor !== null && (int) $actor->id === (int) $target->id) {
            return response()->json(['message' => 'No podés borrar tu propia cuenta.'], 422);
        }

        if ($target->isAdmin()) {
            return response()->json(['message' => 'No se puede borrar una cuenta admin.'], 422);
        }

        $username = (string) $target->username;
        $target->delete();

        if ($username !== '') {
            File::deleteDirectory(storage_path('app/private/cookies/'.$username));
            File::deleteDirectory(storage_path('app/private/tmp-downloads/'.$id));
        }

        return response()->json(null, 204);
    }

    public function approveServerStorage(int $id): UserResource|JsonResponse
    {
        $target = User::query()->find($id);

        if ($target === null) {
            return response()->json(['message' => 'Usuario no encontrado.'], 404);
        }

        if ($target->server_storage_status !== 'pending') {
            return response()->json(['message' => 'El usuario no tiene una solicitud pendiente.'], 422);
        }

        $target->forceFill(['server_storage_status' => 'approved'])->save();

        return new UserResource($target);
    }
}
