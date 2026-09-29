<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Auth\LibraryPathResolver;
use App\Application\ClearDownloads\ClearDownloadsCommand;
use App\Application\ClearDownloads\ClearDownloadsHandler;
use App\Application\CreateDownload\CreateDownloadCommand;
use App\Application\CreateDownload\CreateDownloadHandler;
use App\Application\DeleteDownload\DeleteDownloadCommand;
use App\Application\DeleteDownload\DeleteDownloadHandler;
use App\Application\ListDownloads\ListDownloadsHandler;
use App\Application\ListDownloads\ListDownloadsQuery;
use App\Application\RetryDownload\RetryDownloadCommand;
use App\Application\RetryDownload\RetryDownloadHandler;
use App\Application\Settings\ProviderSettingsResolver;
use App\Domain\Music\Contracts\DownloadJobRepository;
use App\Domain\Music\Exceptions\UnsupportedMusicUrlException;
use App\Domain\Music\ValueObjects\AudioFormat;
use App\Domain\Music\ValueObjects\DownloadStatus;
use App\Domain\Music\ValueObjects\MusicUrl;
use App\Http\Requests\StoreDownloadRequest;
use App\Http\Resources\DownloadJobResource;
use App\Infrastructure\Storage\DownloadedFilesCleanup;
use App\Models\User;
use App\Support\OwnedResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

final class DownloadController extends Controller
{
    public function index(Request $request, ListDownloadsHandler $handler): AnonymousResourceCollection
    {
        $limit = min(max((int) $request->query('limit', 50), 1), 200);
        $user = $this->user($request);
        $jobs = $handler->handle(new ListDownloadsQuery(
            limit: $limit,
            userId: (int) $user->id,
            includeUnowned: $user->isAdmin(),
        ));

        return DownloadJobResource::collection($jobs);
    }

    public function show(int $id, DownloadJobRepository $jobs): DownloadJobResource|JsonResponse
    {
        $job = $jobs->find($id);

        if ($job === null || ! OwnedResource::visible($job['user_id'] ?? null, $this->user(request()))) {
            return response()->json(['message' => 'Download job not found.'], 404);
        }

        return new DownloadJobResource($job);
    }

    public function store(
        StoreDownloadRequest $request,
        CreateDownloadHandler $handler,
        ProviderSettingsResolver $settings,
    ): JsonResponse {
        $formatValue = $request->input('format', $settings->defaultFormat()->value);
        $format = AudioFormat::tryFrom((string) $formatValue) ?? $settings->defaultFormat();
        $provider = $request->input('provider');
        $providerName = is_string($provider) && $provider !== '' ? $provider : null;

        $user = $this->user($request);
        $destination = app(LibraryPathResolver::class)->effectiveDestination($user);

        try {
            $jobId = $handler->handle(new CreateDownloadCommand(
                url: new MusicUrl($request->string('url')->toString()),
                format: $format,
                provider: $providerName,
                userId: (int) $user->id,
                downloadDestination: $destination,
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
        $job = app(DownloadJobRepository::class)->find($id);
        if ($job === null || ! OwnedResource::visible($job['user_id'] ?? null, $this->user(request()))) {
            return response()->json(['message' => 'Download job not found.'], 404);
        }

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

    public function destroy(int $id, DeleteDownloadHandler $handler): JsonResponse
    {
        $job = app(DownloadJobRepository::class)->find($id);
        if ($job === null || ! OwnedResource::visible($job['user_id'] ?? null, $this->user(request()))) {
            return response()->json(['message' => 'Download job not found.'], 404);
        }

        if (! $handler->handle(new DeleteDownloadCommand($id))) {
            return response()->json(['message' => 'Download job not found.'], 404);
        }

        return response()->json(null, 204);
    }

    public function destroyAll(ClearDownloadsHandler $handler): JsonResponse
    {
        $user = $this->user(request());
        $deleted = $handler->handle(new ClearDownloadsCommand(
            userId: (int) $user->id,
            includeUnowned: $user->isAdmin(),
        ));

        return response()->json(['deleted' => $deleted]);
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

    public function artifact(int $id, Request $request, DownloadJobRepository $jobs, DownloadedFilesCleanup $cleanup): BinaryFileResponse|JsonResponse
    {
        $job = $jobs->find($id);

        if ($job === null || ! OwnedResource::visible($job['user_id'] ?? null, $this->user($request))) {
            return response()->json(['message' => 'Download job not found.'], 404);
        }

        if (($job['download_destination'] ?? 'server') !== 'direct' || ($job['status'] ?? '') !== DownloadStatus::Done->value) {
            return response()->json(['message' => 'Este trabajo no tiene un archivo para descargar.'], 422);
        }

        $files = [];
        foreach ($cleanup->pathsForJob($job) as $path) {
            if (is_string($path) && is_file($path)) {
                $files[] = $path;
            }
        }

        if ($files === []) {
            return response()->json(['message' => 'El archivo ya no está disponible.'], 404);
        }

        if (count($files) === 1) {
            return response()->download($files[0], basename($files[0]));
        }

        $zipPath = storage_path('app/private/tmp-downloads/job-'.$id.'.zip');
        File::ensureDirectoryExists(dirname($zipPath));
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return response()->json(['message' => 'No se pudo armar el archivo.'], 500);
        }

        foreach ($files as $file) {
            $zip->addFile($file, basename($file));
        }
        $zip->close();

        return response()->download($zipPath, 'descarga-'.$id.'.zip')->deleteFileAfterSend(true);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
