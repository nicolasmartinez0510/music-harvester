<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Music\ValueObjects\AudioFileMetadata;
use App\Infrastructure\Metadata\MutagenAudioTagWriter;
use Tests\TestCase;

class MutagenAudioTagWriterTest extends TestCase
{
    public function test_sends_display_title_and_cover_to_python_script(): void
    {
        $dir = storage_path('framework/testing/metadata-writer-'.uniqid());
        mkdir($dir, 0755, true);
        $audio = $dir.'/track.mp3';
        file_put_contents($audio, 'audio');
        $script = $dir.'/stub.py';
        file_put_contents($script, <<<'PY'
import json, sys
data = json.load(sys.stdin)
open(data["path"] + ".json", "w").write(json.dumps({
    "title": data["tags"]["title"],
    "artist": data["tags"]["artist"],
    "has_cover": bool(data.get("cover")),
    "lyrics": data["lyrics"]["plain"],
}))
PY);

        config([
            'music.metadata_python' => 'python3',
            'music.metadata_script_path' => $script,
        ]);

        $writer = new MutagenAudioTagWriter;
        $writer->apply($audio, new AudioFileMetadata(
            title: 'Harder',
            titleVersion: 'Remix',
            primaryArtist: 'Daft Punk',
            featuredArtists: ['Pharrell'],
            coverBytes: 'jpeg',
            coverMime: 'image/jpeg',
            lyricsPlain: 'Work it',
        ));

        $written = json_decode((string) file_get_contents($audio.'.json'), true);
        $this->assertSame('Harder (Remix)', $written['title']);
        $this->assertSame('Daft Punk feat. Pharrell', $written['artist']);
        $this->assertTrue($written['has_cover']);
        $this->assertSame('Work it', $written['lyrics']);
    }
}
