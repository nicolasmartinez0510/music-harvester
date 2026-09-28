<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Library\GetUserLibraryHandler;
use App\Application\Library\GetUserLibraryQuery;
use App\Http\Resources\CatalogResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

final class LibraryController extends Controller
{
    public function __invoke(
        Request $request,
        string $provider,
        string $kind,
        GetUserLibraryHandler $handler,
    ): JsonResponse {
        try {
            $hits = $handler->handle(new GetUserLibraryQuery(
                provider: $provider,
                kind: $kind,
                limit: (int) $request->input('limit', 50),
                index: (int) $request->input('index', 0),
            ));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        } catch (RuntimeException $exception) {
            $status = str_contains(strtolower($exception->getMessage()), 'not available')
                || str_contains(strtolower($exception->getMessage()), 'not configured')
                ? 422
                : 502;

            return response()->json(['message' => $exception->getMessage()], $status);
        }

        return response()->json([
            'data' => CatalogResource::hits($hits),
        ]);
    }
}
