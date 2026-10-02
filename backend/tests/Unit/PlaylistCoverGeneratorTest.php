<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\Auth\LibraryPathResolver;
use App\Application\PlaylistCover\PlaylistArtistRanker;
use App\Application\PlaylistCover\PlaylistCoverGenerator;
use App\Domain\Music\Contracts\ArtistPortraitLookup;
use App\Domain\Music\Contracts\PlaylistCoverRenderer;
use App\Domain\Music\Contracts\SavedPlaylistRepository;
use App\Domain\Music\ValueObjects\PlaylistTrackStatus;
use App\Infrastructure\Storage\LocalMusicStorage;
use Mockery;
use Tests\TestCase;

class PlaylistCoverGeneratorTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = storage_path('framework/testing/cover-gen-'.uniqid());
        mkdir($this->base, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->base);
        Mockery::close();
        parent::tearDown();
    }

    public function test_auto_falls_back_to_title_art_when_no_track_covers_exist(): void
    {
        $renderer = new RecordingCoverRenderer();
        $generator = $this->generator($renderer, $this->playlist('auto'), [], new NullPortraitLookup());

        $folder = $this->base.'/playlists/7-country';
        mkdir($folder.'/nested', 0755, true);
        file_put_contents($folder.'/cover.jpg', 'album');
        file_put_contents($folder.'/nested/cover.jpg', 'keep');

        $generator->generate(7);

        $this->assertSame(['mosaic', 'title'], $renderer->modes);
        $this->assertFileExists($folder.'/7-country.jpg');
        $this->assertFileExists($folder.'/cover.jpg');
        $this->assertFileExists($folder.'/nested/cover.jpg');
    }

    public function test_clear_artist_renders_a_portrait(): void
    {
        $portrait = $this->base.'/adele.bin';
        file_put_contents($portrait, 'portrait');
        $renderer = new RecordingCoverRenderer();
        $tracks = [];
        foreach (['Adele', 'Adele', 'Adele', 'Other'] as $index => $artist) {
            $tracks[] = $this->track($index + 1, $artist, PlaylistTrackStatus::Pending->value);
        }

        $generator = $this->generator(
            $renderer,
            $this->playlist('artist'),
            $tracks,
            new FilePortraitLookup($portrait),
        );

        $generator->generate(7);

        $this->assertSame(['portrait'], $renderer->modes);
        $this->assertSame([$portrait], $renderer->imagePaths);
    }

    public function test_unclear_artists_render_a_grid_of_up_to_four(): void
    {
        $portrait = $this->base.'/face.bin';
        file_put_contents($portrait, 'face');
        $renderer = new RecordingCoverRenderer();
        $tracks = [];
        foreach (['A', 'A', 'B', 'B', 'C', 'D', 'E'] as $index => $artist) {
            $tracks[] = $this->track($index + 1, $artist, PlaylistTrackStatus::Pending->value);
        }

        $generator = $this->generator(
            $renderer,
            $this->playlist('artist'),
            $tracks,
            new FilePortraitLookup($portrait),
        );

        $generator->generate(7);

        $this->assertSame(['grid'], $renderer->modes);
        $this->assertCount(4, $renderer->imagePaths);
    }

    public function test_artist_mode_without_photos_uses_title_art(): void
    {
        $renderer = new RecordingCoverRenderer();
        $generator = $this->generator(
            $renderer,
            $this->playlist('artist'),
            [$this->track(1, 'A', PlaylistTrackStatus::Pending->value), $this->track(2, 'B', PlaylistTrackStatus::Pending->value)],
            new NullPortraitLookup(),
        );

        $generator->generate(7);

        $this->assertSame(['title'], $renderer->modes);
    }

    public function test_custom_cover_is_copied_and_not_rendered_again(): void
    {
        $renderer = new RecordingCoverRenderer();
        $generator = $this->generator($renderer, $this->playlist('custom'), [], new NullPortraitLookup());
        $custom = $generator->customPath(7);
        if (! is_dir(dirname($custom))) {
            mkdir(dirname($custom), 0755, true);
        }
        file_put_contents($custom, 'ORIGINAL');

        $generator->generate(7);

        $this->assertSame([], $renderer->modes);
        $this->assertSame('ORIGINAL', file_get_contents($this->base.'/playlists/7-country/7-country.jpg'));

        @unlink($custom);
    }

    /**
     * @param  array<string, mixed>  $playlist
     * @param  list<array<string, mixed>>  $tracks
     */
    private function generator(
        RecordingCoverRenderer $renderer,
        array $playlist,
        array $tracks,
        ArtistPortraitLookup $portraits,
    ): PlaylistCoverGenerator {
        $playlists = Mockery::mock(SavedPlaylistRepository::class);
        $playlists->shouldReceive('find')->with(7)->andReturn($playlist);
        $playlists->shouldReceive('listTracks')->with(7)->andReturn($tracks);

        return new PlaylistCoverGenerator(
            $playlists,
            new LocalMusicStorage($this->base),
            $this->app->make(LibraryPathResolver::class),
            $renderer,
            $portraits,
            new PlaylistArtistRanker(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function playlist(string $mode): array
    {
        return [
            'id' => 7,
            'user_id' => null,
            'title' => 'Country',
            'cover_mode' => $mode,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function track(int $position, string $artist, string $status): array
    {
        return [
            'position' => $position,
            'artist' => $artist,
            'status' => $status,
            'file_path' => null,
        ];
    }

    private function rmTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path.'/'.$item;
            if (is_dir($full)) {
                $this->rmTree($full);
            } else {
                @unlink($full);
            }
        }

        @rmdir($path);
    }
}

final class RecordingCoverRenderer implements PlaylistCoverRenderer
{
    /** @var list<string> */
    public array $modes = [];

    /** @var list<string> */
    public array $imagePaths = [];

    public function render(array $spec): array
    {
        $this->modes[] = $spec['mode'];
        $this->imagePaths = $spec['image_paths'] ?? [];
        if ($spec['mode'] === 'mosaic') {
            return ['ok' => false, 'images' => 0, 'reason' => 'no_images'];
        }

        $output = $spec['output'];
        if (! is_dir(dirname($output))) {
            mkdir(dirname($output), 0755, true);
        }
        file_put_contents($output, $spec['mode']);

        return ['ok' => true, 'images' => count($this->imagePaths), 'reason' => null];
    }
}

final class NullPortraitLookup implements ArtistPortraitLookup
{
    public function portraitUrl(string $artistName): ?string
    {
        return null;
    }
}

final class FilePortraitLookup implements ArtistPortraitLookup
{
    public function __construct(
        private string $path,
    ) {}

    public function portraitUrl(string $artistName): ?string
    {
        return 'file://'.$this->path;
    }
}
