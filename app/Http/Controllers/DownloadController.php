<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\CreateDownload\CreateDownloadCommand;
use App\Application\CreateDownload\CreateDownloadHandler;
use App\Application\ListDownloads\ListDownloadsHandler;
use App\Application\ListDownloads\ListDownloadsQuery;
use App\Application\RetryDownload\RetryDownloadCommand;
use App\Application\RetryDownload\RetryDownloadHandler;
use App\Domain\Music\Contracts\DownloadJobRepository;
use App\Domain\Music\Exceptions\UnsupportedMusicUrlException;
use App\Domain\Music\ValueObjects\AudioFormat;
use App\Domain\Music\ValueObjects\MusicUrl;
use App\Http\Requests\StoreDownloadRequest;
use App\Http\Resources\DownloadJobResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class DownloadController extends Controller
{
    public function index(Request $request, ListDownloadsHandler $handler): AnonymousResourceCollection
    {
        $limit = min(max((int) $request->query('limit', 50), 1), 100);
        $jobs = $handler->handle(new ListDownloadsQuery($limit));

        return DownloadJobResource::collection($jobs);
    }

    public function show(int $id, DownloadJobRepository $jobs): DownloadJobResource|JsonResponse
    {
        $job = $jobs->find($id);

        if ($job === null) {
            return response()->json(['message' => 'Download job not found.'], 404);
        }

        return new DownloadJobResource($job);
    }

    public function store(
        StoreDownloadRequest $request,
        CreateDownloadHandler $handler,
    ): JsonResponse {
        $formatValue = $request->input('format', config('music.default_format'));
        $format = AudioFormat::tryFrom((string) $formatValue) ?? AudioFormat::Mp3_320;

        try {
            $jobId = $handler->handle(new CreateDownloadCommand(
                url: new MusicUrl($request->string('url')->toString()),
                format: $format,
            ));
        } catch (UnsupportedMusicUrlException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return (new DownloadJobResource($this->requireJob($jobId)))
            ->response()
            ->setStatusCode(202);
    }

    public function retry(int $id, RetryDownloadHandler $handler): JsonResponse
    {
        $retried = $handler->handle(new RetryDownloadCommand($id));

        if (! $retried) {
            $job = app(DownloadJobRepository::class)->find($id);

            if ($job === null) {
                return response()->json(['message' => 'Download job not found.'], 404);
            }

            return response()->json(['message' => 'Only failed downloads can be retried.'], 422);
        }

        return (new DownloadJobResource($this->requireJob($id)))
            ->response()
            ->setStatusCode(202);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireJob(int $id): array
    {
        $job = app(DownloadJobRepository::class)->find($id);

        if ($job === null) {
            abort(404, 'Download job not found.');
        }

        return $job;
    }
}
