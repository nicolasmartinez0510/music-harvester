<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\PlaylistCover\PlaylistCoverGenerator;
use App\Domain\Music\Contracts\PlaylistCoverRenderer;
use App\Domain\Music\ValueObjects\PlaylistSyncStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlaylistCoverApiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $authenticate = true;

    public function test_upload_sets_custom_mode_and_a_later_generate_keeps_the_file(): void
    {
        $musicPath = storage_path('framework/testing/cover-api-'.uniqid());
        config(['music.path' => $musicPath]);

        $renderer = new class implements PlaylistCoverRenderer
        {
            /** @var list<string> */
            public array $modes = [];

            public function render(array $spec): array
            {
                $this->modes[] = $spec['mode'];
                $output = $spec['output'];
                if (! is_dir(dirname($output))) {
                    mkdir(dirname($output), 0755, true);
                }
                file_put_contents($output, $spec['mode'] === 'upload' ? 'ORIGINAL' : 'REGENERATED');

                return ['ok' => true, 'images' => 1, 'reason' => null];
            }
        };
        $this->app->instance(PlaylistCoverRenderer::class, $renderer);

        $playlistId = $this->insertPlaylist(['title' => 'Country']);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $uploadPath = $musicPath.'-upload.png';
        file_put_contents($uploadPath, $png);

        $response = $this->post('/api/playlists/'.$playlistId.'/cover', [
            'cover' => new UploadedFile($uploadPath, 'cover.png', 'image/png', null, true),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.cover_mode', 'custom');

        $published = $musicPath.'/playlists/'.$playlistId.'-country/'.$playlistId.'-country.jpg';
        $this->assertSame('ORIGINAL', file_get_contents($published));

        $this->app->make(PlaylistCoverGenerator::class)->generate($playlistId);

        $this->assertSame(['upload'], $renderer->modes);
        $this->assertSame('ORIGINAL', file_get_contents($published));
        $this->get('/api/playlists/'.$playlistId.'/cover')
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');

        $this->rmTree($musicPath);
        @unlink($uploadPath);
        @unlink(storage_path('app/private/playlist-covers/'.$playlistId.'.jpg'));
    }

    public function test_custom_mode_without_an_upload_is_rejected(): void
    {
        $playlistId = $this->insertPlaylist();

        $this->putJson('/api/playlists/'.$playlistId, [
            'cover_mode' => 'custom',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Subí una imagen para usar como portada.');
    }

    public function test_saving_title_mode_renders_the_cover(): void
    {
        $musicPath = storage_path('framework/testing/cover-title-'.uniqid());
        config(['music.path' => $musicPath]);
        $this->app->instance(PlaylistCoverRenderer::class, new class implements PlaylistCoverRenderer
        {
            public function render(array $spec): array
            {
                $output = $spec['output'];
                if (! is_dir(dirname($output))) {
                    mkdir(dirname($output), 0755, true);
                }
                file_put_contents($output, 'TITLE');

                return ['ok' => true, 'images' => 0, 'reason' => null];
            }
        });

        $playlistId = $this->insertPlaylist(['title' => 'Dance']);

        $this->putJson('/api/playlists/'.$playlistId, [
            'cover_mode' => 'title',
        ])
            ->assertOk()
            ->assertJsonPath('data.cover_mode', 'title');

        $this->assertSame(
            'TITLE',
            file_get_contents($musicPath.'/playlists/'.$playlistId.'-dance/'.$playlistId.'-dance.jpg'),
        );

        $this->rmTree($musicPath);
    }

    public function test_delete_removes_the_stored_custom_cover(): void
    {
        $musicPath = storage_path('framework/testing/cover-delete-'.uniqid());
        config(['music.path' => $musicPath]);

        $playlistId = $this->insertPlaylist();
        $custom = storage_path('app/private/playlist-covers/'.$playlistId.'.jpg');
        if (! is_dir(dirname($custom))) {
            mkdir(dirname($custom), 0755, true);
        }
        file_put_contents($custom, 'keep');

        $this->deleteJson('/api/playlists/'.$playlistId)->assertNoContent();

        $this->assertFileDoesNotExist($custom);
        $this->rmTree($musicPath);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertPlaylist(array $overrides = []): int
    {
        return (int) DB::table('saved_playlists')->insertGetId(array_merge([
            'provider' => 'deezer',
            'url' => 'https://www.deezer.com/playlist/1',
            'title' => 'Mix',
            'sync_enabled' => true,
            'sync_interval_minutes' => 5,
            'default_format' => null,
            'cover_mode' => 'auto',
            'last_synced_at' => null,
            'last_sync_status' => PlaylistSyncStatus::Idle->value,
            'last_sync_error' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
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
