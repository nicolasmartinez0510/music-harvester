<?php

declare(strict_types=1);

namespace App\Infrastructure\Metadata;

use App\Domain\Music\Contracts\AudioCoverProbe;
use RuntimeException;

/**
 * One long-lived mutagen process for the whole run. Spawning Python per file
 * looks frozen on a NAS and prints nothing until the first result comes back.
 */
final class MutagenAudioCoverProbe implements AudioCoverProbe
{
    private const TIMEOUT_SECONDS = 60;

    /** @var resource|null */
    private $process = null;

    /** @var resource|null */
    private $stdin = null;

    /** @var resource|null */
    private $stdout = null;

    public function hasCover(string $filePath): bool
    {
        if (! is_file($filePath)) {
            throw new RuntimeException('Audio file not found: '.$filePath);
        }
        if (str_contains($filePath, "\n") || str_contains($filePath, "\r")) {
            throw new RuntimeException('Audio path contains a newline: '.$filePath);
        }

        $this->ensureWorker();

        $written = fwrite($this->stdin, $filePath."\n");
        if ($written === false || ! fflush($this->stdin)) {
            $this->stopWorker();
            throw new RuntimeException('cover probe stopped accepting paths: '.$filePath);
        }

        $line = fgets($this->stdout, 1_048_576);
        $meta = stream_get_meta_data($this->stdout);
        if (($meta['timed_out'] ?? false) === true) {
            $this->stopWorker();
            throw new RuntimeException('cover probe timed out: '.$filePath);
        }
        if ($line === false) {
            $this->stopWorker();
            throw new RuntimeException('cover probe closed unexpectedly: '.$filePath);
        }

        $answer = trim($line);
        if ($answer === 'yes') {
            return true;
        }
        if ($answer === 'no') {
            return false;
        }
        if (str_starts_with($answer, 'err ')) {
            throw new RuntimeException(substr($answer, 4));
        }

        throw new RuntimeException('Unexpected cover probe output: '.$answer);
    }

    public function __destruct()
    {
        $this->stopWorker();
    }

    private function ensureWorker(): void
    {
        if (is_resource($this->process)) {
            $status = proc_get_status($this->process);
            if (($status['running'] ?? false) === true) {
                return;
            }
            $this->stopWorker();
        }

        $python = (string) config('music.metadata_python', 'python3');
        $script = (string) config('music.metadata_script_path');
        if ($script === '' || ! is_file($script)) {
            throw new RuntimeException('Metadata script not found: '.$script);
        }

        $pipes = [];
        $process = proc_open(
            [$python, $script, '--has-cover-stream'],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['file', '/dev/null', 'a'],
            ],
            $pipes,
        );
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start cover probe.');
        }

        stream_set_blocking($pipes[1], true);
        stream_set_timeout($pipes[1], self::TIMEOUT_SECONDS);
        $this->process = $process;
        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
    }

    private function stopWorker(): void
    {
        if (is_resource($this->stdin)) {
            fclose($this->stdin);
        }
        if (is_resource($this->stdout)) {
            fclose($this->stdout);
        }
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->stdin = null;
        $this->stdout = null;
        $this->process = null;
    }
}
