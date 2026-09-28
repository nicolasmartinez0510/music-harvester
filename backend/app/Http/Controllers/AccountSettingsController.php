<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Infrastructure\Auth\UserCredentialStore;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;

final class AccountSettingsController extends Controller
{
    public function updatePreferences(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'download_destination' => ['sometimes', 'required', Rule::in(['direct', 'server'])],
            'avatar_id' => ['sometimes', 'required', 'string', Rule::in(config('avatars.ids'))],
        ]);

        if (isset($data['avatar_id'])) {
            $user->avatar_id = $data['avatar_id'];
        }

        if (isset($data['download_destination'])) {
            $destination = $data['download_destination'];
            if ($destination === 'server' && ! $user->isAdmin() && $user->server_storage_status === 'none') {
                $user->server_storage_status = 'pending';
            }
            $user->download_destination = $destination;
        }

        $user->save();

        return new UserResource($user->fresh() ?? $user);
    }

    public function updateDeezerArl(Request $request, UserCredentialStore $credentials): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'arl' => ['required', 'string', 'min:32', 'max:500'],
        ]);

        $credentials->setArl((int) $user->id, $data['arl']);

        return response()->json(['data' => ['provider_deezer_arl_configured' => true]]);
    }

    public function uploadYoutubeCookies(Request $request, UserCredentialStore $credentials): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            'cookies' => ['required', 'file', 'max:512'],
        ]);

        $upload = $request->file('cookies');
        if ($upload === null) {
            return response()->json(['message' => 'Falta el archivo de cookies.'], 422);
        }

        $contents = file_get_contents($upload->getRealPath());
        if (! is_string($contents) || strlen($contents) < 10 || str_contains($contents, "\0")) {
            return response()->json(['message' => 'El archivo de cookies no es válido.'], 422);
        }

        $username = (string) $user->username;
        $directory = storage_path('app/private/cookies/'.$username);
        File::ensureDirectoryExists($directory);
        $path = $directory.'/cookies.txt';
        File::put($path, $contents);

        $credentials->setCookiesPath((int) $user->id, $path);

        return response()->json(['data' => ['provider_youtube_music_cookies_configured' => true]]);
    }

    public function listFavorites(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $rows = DB::table('artist_favorites')
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn ($row) => [
                'provider' => $row->provider,
                'id' => $row->external_id,
                'name' => $row->name,
                'cover_url' => $row->image_url,
            ])
            ->all();

        return response()->json(['data' => $rows]);
    }

    public function toggleFavorite(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'provider' => ['required', 'string', 'max:40'],
            'id' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:200'],
            'cover_url' => ['nullable', 'string', 'max:500'],
        ]);

        $existing = DB::table('artist_favorites')
            ->where('user_id', $user->id)
            ->where('provider', $data['provider'])
            ->where('external_id', $data['id'])
            ->first();

        if ($existing !== null) {
            DB::table('artist_favorites')->where('id', $existing->id)->delete();

            return response()->json(['data' => ['favorited' => false]]);
        }

        DB::table('artist_favorites')->insert([
            'user_id' => $user->id,
            'provider' => $data['provider'],
            'external_id' => $data['id'],
            'name' => $data['name'],
            'image_url' => $data['cover_url'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['data' => ['favorited' => true]]);
    }
}
