<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Music\ValueObjects\CatalogAlbum;
use App\Domain\Music\ValueObjects\CatalogArtist;
use App\Domain\Music\ValueObjects\CatalogHit;
use App\Domain\Music\ValueObjects\CatalogPlaylist;

final class CatalogResource
{
    /**
     * @return array<string, mixed>
     */
    public static function hit(CatalogHit $hit): array
    {
        return [
            'id' => $hit->id,
            'type' => $hit->type->value,
            'title' => $hit->title,
            'subtitle' => $hit->subtitle,
            'cover_url' => $hit->coverUrl,
            'canonical_url' => $hit->canonicalUrl,
            'nb_tracks' => $hit->nbTracks,
            'release_date' => $hit->releaseDate,
            'fans' => $hit->fans,
            'record_type' => $hit->recordType,
        ];
    }

    /**
     * @param  list<CatalogHit>  $hits
     * @return list<array<string, mixed>>
     */
    public static function hits(array $hits): array
    {
        return array_map(self::hit(...), $hits);
    }

    /**
     * @return array<string, mixed>
     */
    public static function artist(CatalogArtist $artist): array
    {
        return [
            'id' => $artist->id,
            'type' => 'artist',
            'title' => $artist->name,
            'name' => $artist->name,
            'cover_url' => $artist->coverUrl,
            'canonical_url' => $artist->canonicalUrl,
            'nb_fans' => $artist->nbFans,
            'description' => $artist->description,
            'top_tracks' => self::hits($artist->topTracks),
            'albums' => self::hits($artist->albums),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function album(CatalogAlbum $album): array
    {
        return [
            'id' => $album->id,
            'type' => 'album',
            'title' => $album->title,
            'subtitle' => $album->artistName,
            'artist_name' => $album->artistName,
            'cover_url' => $album->coverUrl,
            'canonical_url' => $album->canonicalUrl,
            'nb_tracks' => $album->nbTracks,
            'tracks' => self::hits($album->tracks),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function playlist(CatalogPlaylist $playlist): array
    {
        return [
            'id' => $playlist->id,
            'type' => 'playlist',
            'title' => $playlist->title,
            'subtitle' => $playlist->creatorName,
            'creator_name' => $playlist->creatorName,
            'cover_url' => $playlist->coverUrl,
            'canonical_url' => $playlist->canonicalUrl,
            'nb_tracks' => $playlist->nbTracks,
            'tracks' => self::hits($playlist->tracks),
        ];
    }
}
