<?php

declare(strict_types=1);

namespace App\Domain\Music\ValueObjects;

final readonly class AudioFileMetadata
{
    /**
     * @param  list<string>  $featuredArtists
     * @param  list<string>  $composers
     * @param  list<string>  $genres
     */
    public function __construct(
        public string $title,
        public ?string $titleVersion = null,
        public ?string $primaryArtist = null,
        public ?string $albumArtist = null,
        public array $featuredArtists = [],
        public array $composers = [],
        public ?string $albumTitle = null,
        public ?int $trackNumber = null,
        public ?int $trackTotal = null,
        public ?int $discNumber = null,
        public ?int $discTotal = null,
        public ?string $releaseDate = null,
        public array $genres = [],
        public ?string $isrc = null,
        public ?string $deezerTrackId = null,
        public ?string $coverBytes = null,
        public ?string $coverMime = null,
        public ?string $lyricsPlain = null,
        public ?string $lyricsSynced = null,
    ) {}

    public function displayTitle(): string
    {
        $version = trim((string) $this->titleVersion);
        if ($version === '') {
            return $this->title;
        }

        if (mb_stripos($this->title, $version) !== false) {
            return $this->title;
        }

        return $this->title.' ('.$version.')';
    }

    public function artistCredit(): ?string
    {
        $primary = trim((string) $this->primaryArtist);
        $featured = [];
        foreach ($this->featuredArtists as $name) {
            $name = trim($name);
            if ($name === '' || strcasecmp($name, $primary) === 0 || in_array($name, $featured, true)) {
                continue;
            }
            $featured[] = $name;
        }

        if ($primary === '') {
            return $featured === [] ? null : implode(', ', $featured);
        }

        if ($featured === []) {
            return $primary;
        }

        return $primary.' feat. '.implode(', ', $featured);
    }
}
