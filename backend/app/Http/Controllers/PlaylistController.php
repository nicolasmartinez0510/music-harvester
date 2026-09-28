<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\DeleteSavedPlaylist\DeleteSavedPlaylistCommand;
use App\Application\DeleteSavedPlaylist\DeleteSavedPlaylistHandler;
use App\Application\GetSavedPlaylist\GetSavedPlaylistHandler;
use App\Application\GetSavedPlaylist\GetSavedPlaylistQuery;
use App\Application\ListSavedPlaylists\ListSavedPlaylistsHandler;
use App\Application\ListSavedPlaylists\ListSavedPlaylistsQuery;
use App\Application\SavePlaylist\SavePlaylistCommand;
use App\Application\SavePlaylist\SavePlaylistHandler;
use App\Application\SyncSavedPlaylist\SyncSavedPlaylistCommand;
use App\Application\SyncSavedPlaylist\SyncSavedPlaylistHandler;
use App\Application\UpdateSavedPlaylist\UpdateSavedPlaylistCommand;
use App\Application\UpdateSavedPlaylist\UpdateSavedPlaylistHandler;
use App\Domain\Music\Contracts\SavedPlaylistRepository;
use App\Domain\Music\Exceptions\UnsupportedMusicUrlException;
use App\Domain\Music\ValueObjects\MusicUrl;
use App\Http\Requests\StorePlaylistRequest;
use App\Http\Requests\UpdatePlaylistRequest;
use App\Http\Resources\SavedPlaylistResource;
use App\Http\Resources\SavedPlaylistTrackResource;
use App\Models\User;
use App\Support\OwnedResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class PlaylistController extends Controller
{
    public function index(Request $request, ListSavedPlaylistsHandler $handler): AnonymousResourceCollection
    {
        $user = $this->user($request);

        return SavedPlaylistResource::collection($handler->handle(new ListSavedPlaylistsQuery(
            userId: (int) $user->id,
            includeUnowned: $user->isAdmin(),
        )));
    }

    public function store(
        StorePlaylistRequest $request,
        SavePlaylistHandler $handler,
        SavedPlaylistRepository $playlists,
    ): JsonResponse {
        $user = $this->user($request);

        try {
            $result = $handler->handle(new SavePlaylistCommand(
                url: new MusicUrl($request->string('url')->toString()),
                syncNow: $request->boolean('sync_now', true),
                syncEnabled: $request->boolean('sync_enabled', true),
                syncIntervalMinutes: $request->has('sync_interval_minutes')
                    ? (int) $request->input('sync_interval_minutes')
                    : null,
                userId: (int) $user->id,
            ));
        } catch (UnsupportedMusicUrlException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $playlist = $result['playlist'];
        $counts = $playlists->trackCounts((int) $playlist['id']);

        return (new SavedPlaylistResource(array_merge($playlist, ['counts' => $counts])))
            ->response()
            ->setStatusCode($result['created'] ? 201 : 200);
    }

    public function show(int $id, GetSavedPlaylistHandler $handler, SavedPlaylistRepository $playlists): JsonResponse
    {
        if ($this->visiblePlaylist($id, $playlists) === null) {
            return response()->json(['message' => 'Playlist not found.'], 404);
        }

        $result = $handler->handle(new GetSavedPlaylistQuery($id));

        if ($result === null) {
            return response()->json(['message' => 'Playlist not found.'], 404);
        }

        return response()->json([
            'data' => array_merge(
                (new SavedPlaylistResource(array_merge($result['playlist'], ['counts' => $result['counts']])))->resolve(),
                [
                    'tracks' => SavedPlaylistTrackResource::collection($result['tracks'])->resolve(),
                ],
            ),
        ]);
    }

    public function update(
        int $id,
        UpdatePlaylistRequest $request,
        UpdateSavedPlaylistHandler $handler,
        SavedPlaylistRepository $playlists,
    ): SavedPlaylistResource|JsonResponse {
        if ($this->visiblePlaylist($id, $playlists) === null) {
            return response()->json(['message' => 'Playlist not found.'], 404);
        }

        try {
            $playlist = $handler->handle(new UpdateSavedPlaylistCommand(
                id: $id,
                attributes: $request->validated(),
            ));
        } catch (\ValueError $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        if ($playlist === null) {
            return response()->json(['message' => 'Playlist not found.'], 404);
        }

        return new SavedPlaylistResource(array_merge($playlist, [
            'counts' => $playlists->trackCounts($id),
        ]));
    }

    public function destroy(int $id, DeleteSavedPlaylistHandler $handler, SavedPlaylistRepository $playlists): JsonResponse
    {
        if ($this->visiblePlaylist($id, $playlists) === null) {
            return response()->json(['message' => 'Playlist not found.'], 404);
        }

        if (! $handler->handle(new DeleteSavedPlaylistCommand($id))) {
            return response()->json(['message' => 'Playlist not found.'], 404);
        }

        return response()->json(null, 204);
    }

    public function sync(
        int $id,
        SyncSavedPlaylistHandler $handler,
        SavedPlaylistRepository $playlists,
    ): JsonResponse {
        if ($this->visiblePlaylist($id, $playlists) === null) {
            return response()->json(['message' => 'Playlist not found.'], 404);
        }

        $result = $handler->handle(new SyncSavedPlaylistCommand($id));

        if ($result === null) {
            return response()->json(['message' => 'Playlist not found.'], 404);
        }

        if ($result['already_running'] ?? false) {
            return response()->json(['message' => 'Playlist sync is already running.'], 422);
        }

        $playlist = $result['playlist'];

        return (new SavedPlaylistResource(array_merge($playlist, [
            'counts' => $playlists->trackCounts($id),
        ])))
            ->response()
            ->setStatusCode(202);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function visiblePlaylist(int $id, SavedPlaylistRepository $playlists): ?array
    {
        $playlist = $playlists->find($id);
        $user = request()->user();

        if ($playlist === null || ! $user instanceof User || ! OwnedResource::visible($playlist['user_id'] ?? null, $user)) {
            return null;
        }

        return $playlist;
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
