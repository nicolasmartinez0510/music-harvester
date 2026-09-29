<?php

declare(strict_types=1);

namespace App\Infrastructure\Metadata;

use App\Domain\Music\Contracts\AudioTagWriter;
use App\Domain\Music\ValueObjects\AudioFileMetadata;
use RuntimeException;
use Symfony\Component\Process\Process;

final class MutagenAudioTagWriter implements AudioTagWriter
{
    public function apply(string $filePath, AudioFileMetadata $metadata): void
    {
        if (! is_file($filePath)) {
            throw new RuntimeException('Audio file not found: '.$filePath);
        }

        $python = (string) config('music.metadata_python', 'python3');
        $script = (string) config('music.metadata_script_path');
        if ($script === '' || ! is_file($script)) {
            throw new RuntimeException('Metadata script not found: '.$script);
        }

        $payload = [
            'path' => $filePath,
            'tags' => [
                'title' => $metadata->displayTitle(),
                'artist' => $metadata->artistCredit(),
                'album_artist' => $metadata->albumArtist,
                'album' => $metadata->albumTitle,
                'track_number' => $metadata->trackNumber,
                'track_total' => $metadata->trackTotal,
                'disc_number' => $metadata->discNumber,
                'disc_total' => $metadata->discTotal,
                'date' => $metadata->releaseDate,
                'genres' => $metadata->genres,
                'composers' => $metadata->composers,
                'isrc' => $metadata->isrc,
                'deezer_track_id' => $metadata->deezerTrackId,
            ],
            'cover' => $metadata->coverBytes !== null && $metadata->coverBytes !== ''
                ? base64_encode($metadata->coverBytes)
                : null,
            'cover_mime' => $metadata->coverMime,
            'lyrics' => [
                'plain' => $metadata->lyricsPlain,
                'synced' => $metadata->lyricsSynced,
            ],
        ];

        $process = new Process([$python, $script]);
        $process->setInput(json_encode($payload, JSON_THROW_ON_ERROR));
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful()) {
            $message = trim($process->getErrorOutput() ?: $process->getOutput());
            throw new RuntimeException($message !== '' ? $message : 'mutagen tagging failed.');
        }
    }
}
