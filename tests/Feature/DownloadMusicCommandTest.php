<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DownloadMusicCommandTest extends TestCase
{
    public function test_music_commands_are_registered(): void
    {
        $this->assertSame(0, Artisan::call('music:download', ['--help' => true]));
        $this->assertStringContainsString('Enqueue a download from a YouTube Music URL', Artisan::output());

        $this->assertSame(0, Artisan::call('music:process', ['--help' => true]));
        $this->assertStringContainsString('Process a pending or failed download job', Artisan::output());
    }
}
