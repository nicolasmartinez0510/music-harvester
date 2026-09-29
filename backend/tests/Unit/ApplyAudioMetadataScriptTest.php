<?php

declare(strict_types=1);

namespace Tests\Unit;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class ApplyAudioMetadataScriptTest extends TestCase
{
    public function test_python_script_writes_mp3_tags_when_mutagen_is_available(): void
    {
        $probe = new Process(['python3', '-c', 'import mutagen']);
        $probe->run();
        if (! $probe->isSuccessful()) {
            $this->markTestSkipped('mutagen is not installed');
        }

        $script = dirname(base_path()).'/docker/scripts/test_apply_audio_metadata.py';
        $this->assertFileExists($script);

        $process = new Process(['python3', $script]);
        $process->setTimeout(60);
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            trim($process->getErrorOutput()."\n".$process->getOutput()),
        );
    }
}
