<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Catalog\GetCatalogAlbumHandler;
use App\Application\Catalog\GetCatalogAlbumQuery;
use App\Application\Catalog\GetCatalogArtistHandler;
use App\Application\Catalog\GetCatalogArtistQuery;
use App\Application\Catalog\GetCatalogPlaylistHandler;
use App\Application\Catalog\GetCatalogPlaylistQuery;
use App\Application\Catalog\SearchCatalogHandler;
use App\Application\Catalog\SearchCatalogQuery;
use App\Domain\Music\ValueObjects\CatalogType;
use App\Http\Requests\SearchCatalogRequest;
use App\Http\Resources\CatalogResource;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use RuntimeException;

final class CatalogController extends Controller
{
    public function search(
        SearchCatalogRequest $request,
        SearchCatalogHandler $handler,
    ): JsonResponse {
        $type = CatalogType::tryFrom((string) $request->input('type', 'all')) ?? CatalogType::All;

        try {
            $hits = $handler->handle(new SearchCatalogQuery(
                provider: $request->string('provider')->toString(),
                q: $request->string('q')->toString(),
                type: $type,
                limit: (int) $request->input('limit', 25),
                index: (int) $request->input('index', 0),
            ));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        }

        return response()->json([
            'data' => CatalogResource::hits($hits),
        ]);
    }

    public function artist(
        string $provider,
        string $id,
        GetCatalogArtistHandler $handler,
    ): JsonResponse {
        try {
            $artist = $handler->handle(new GetCatalogArtistQuery($provider, $id));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        }

        return response()->json([
            'data' => CatalogResource::artist($artist),
        ]);
    }

    public function album(
        string $provider,
        string $id,
        GetCatalogAlbumHandler $handler,
    ): JsonResponse {
        try {
            $album = $handler->handle(new GetCatalogAlbumQuery($provider, $id));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        }

        return response()->json([
            'data' => CatalogResource::album($album),
        ]);
    }

    public function playlist(
        string $provider,
        string $id,
        GetCatalogPlaylistHandler $handler,
    ): JsonResponse {
        try {
            $playlist = $handler->handle(new GetCatalogPlaylistQuery($provider, $id));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        }

        return response()->json([
            'data' => CatalogResource::playlist($playlist),
        ]);
    }
}
