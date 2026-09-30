<?php

declare(strict_types=1);

namespace App\Infrastructure\Metadata;

use App\Domain\Music\Contracts\AudioCoverProbe;
use RuntimeException;
use Symfony\Component\Process\Process;

final class MutagenAudioCoverProbe implements AudioCoverProbe
{
    public function hasCover(string $filePath): bool
    {
        if (! is_file($filePath)) {
            throw new RuntimeException('Audio file not found: '.$filePath);
        }

        $python = (string) config('music.metadata_python', 'python3');
        $script = (string) config('music.metadata_script_path');
        if ($script === '' || ! is_file($script)) {
            throw new RuntimeException('Metadata script not found: '.$script);
        }

        $process = new Process([$python, $script, '--has-cover', $filePath]);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            $message = trim($process->getErrorOutput() ?: $process->getOutput());
            throw new RuntimeException($message !== '' ? $message : 'cover probe failed.');
        }

        $answer = trim($process->getOutput());
        if ($answer === 'yes') {
            return true;
        }
        if ($answer === 'no') {
            return false;
        }

        throw new RuntimeException('Unexpected cover probe output: '.$answer);
    }
}
