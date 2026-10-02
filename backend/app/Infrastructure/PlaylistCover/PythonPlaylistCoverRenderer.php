<?php

declare(strict_types=1);

namespace App\Infrastructure\PlaylistCover;

use App\Domain\Music\Contracts\PlaylistCoverRenderer;
use RuntimeException;
use Symfony\Component\Process\Process;

final class PythonPlaylistCoverRenderer implements PlaylistCoverRenderer
{
    public function render(array $spec): array
    {
        $python = (string) config('music.metadata_python', 'python3');
        $script = (string) config('music.playlist_cover_script_path');
        if ($script === '' || ! is_file($script)) {
            throw new RuntimeException('Playlist cover script not found: '.$script);
        }

        $process = new Process([$python, $script]);
        $process->setInput(json_encode($spec, JSON_THROW_ON_ERROR));
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            $message = trim($process->getErrorOutput() ?: $process->getOutput());
            throw new RuntimeException($message !== '' ? $message : 'playlist cover render failed.');
        }

        $decoded = json_decode(trim($process->getOutput()), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('playlist cover render returned invalid json.');
        }

        return [
            'ok' => (bool) ($decoded['ok'] ?? false),
            'images' => (int) ($decoded['images'] ?? 0),
            'reason' => isset($decoded['reason']) && is_string($decoded['reason']) && $decoded['reason'] !== ''
                ? $decoded['reason']
                : null,
        ];
    }
}
