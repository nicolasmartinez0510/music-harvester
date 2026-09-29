<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Settings\ProviderSettingsResolver;
use App\Domain\Music\Contracts\DownloadJobRepository;
use App\Infrastructure\Storage\DownloadedFilesCleanup;
use Illuminate\Console\Command;

final class ProbeDownloadFilesCommand extends Command
{
    protected $signature = 'downloads:probe {id? : Download job id (defaults to latest)}';

    protected $description = 'Diagnose why a download job reports missing files';

    public function handle(
        DownloadJobRepository $jobs,
        DownloadedFilesCleanup $cleanup,
        ProviderSettingsResolver $settings,
    ): int {
        $id = $this->argument('id');
        $job = $id !== null
            ? $jobs->find((int) $id)
            : ($jobs->listRecent(1)[0] ?? null);

        if ($job === null) {
            $this->error('No download job found.');

            return self::FAILURE;
        }

        $present = $cleanup->filesPresent($job);
        $resolved = $cleanup->resolveExistingFiles($job);

        $this->info('Job #'.$job['id'].' status='.$job['status']);
        $this->line('destination_path: '.(($job['destination_path'] ?? null) ?: 'null'));
        $this->line('downloaded_paths: '.json_encode($job['downloaded_paths'] ?? null));
        $this->line('files_present: '.($present ? 'true' : 'false'));
        $this->line('musicPath(): '.$settings->musicPath());
        $this->line('config music.path: '.(string) config('music.path'));
        $this->line('resolved files ('.count($resolved).'):');
        foreach ($resolved as $path) {
            $this->line('  - '.$path);
        }

        foreach ($cleanup->pathsForJob($job) as $path) {
            $this->newLine();
            $this->line('recorded: '.$path);
            $this->line('  is_file='.(is_file($path) ? '1' : '0')
                .' is_readable='.(is_readable($path) ? '1' : '0')
                .' realpath='.var_export(@realpath($path), true));
            $parent = dirname($path);
            $listing = @scandir($parent);
            $this->line('  parent='.$parent
                .' is_dir='.(is_dir($parent) ? '1' : '0')
                .' scandir='.json_encode($listing === false ? 'DENIED/MISSING' : $listing));
        }

        return self::SUCCESS;
    }
}
