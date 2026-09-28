<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\ListProviders\ListProvidersHandler;
use Illuminate\Http\JsonResponse;

final class ProvidersController extends Controller
{
    public function __invoke(ListProvidersHandler $handler): JsonResponse
    {
        return response()->json([
            'data' => $handler->handle(),
        ]);
    }
}
