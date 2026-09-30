#!/usr/bin/env python3
"""Write ID3 / Vorbis / MP4 tags from a JSON payload on stdin."""

from __future__ import annotations

import base64
import json
import re
import sys
from pathlib import Path


def main() -> int:
    try:
        payload = json.load(sys.stdin)
    except json.JSONDecodeError as exc:
        print(f"invalid json: {exc}", file=sys.stderr)
        return 2

    path = payload.get("path")
    if not isinstance(path, str) or path == "":
        print("missing path", file=sys.stderr)
        return 2

    tags = payload.get("tags") if isinstance(payload.get("tags"), dict) else {}
    lyrics = payload.get("lyrics") if isinstance(payload.get("lyrics"), dict) else {}
    cover = decode_cover(payload.get("cover"))
    cover_mime = payload.get("cover_mime") if isinstance(payload.get("cover_mime"), str) else "image/jpeg"

    ext = Path(path).suffix.lower()
    try:
        if ext == ".mp3":
            apply_mp3(path, tags, cover, cover_mime, lyrics)
        elif ext == ".flac":
            apply_flac(path, tags, cover, cover_mime, lyrics)
        elif ext in {".m4a", ".mp4", ".aac"}:
            apply_mp4(path, tags, cover, cover_mime, lyrics)
        else:
            print(f"unsupported extension: {ext or '(none)'}", file=sys.stderr)
            return 2
    except Exception as exc:  # noqa: BLE001 — surface mutagen errors to PHP
        print(str(exc), file=sys.stderr)
        return 1

    return 0


def decode_cover(value: object) -> bytes | None:
    if not isinstance(value, str) or value == "":
        return None
    try:
        data = base64.b64decode(value, validate=False)
    except Exception:  # noqa: BLE001
        return None
    return data or None


def text(tags: dict, key: str) -> str | None:
    value = tags.get(key)
    if value is None:
        return None
    rendered = str(value).strip()
    return rendered or None


def integer(tags: dict, key: str) -> int | None:
    value = tags.get(key)
    if isinstance(value, bool) or value is None:
        return None
    try:
        number = int(value)
    except (TypeError, ValueError):
        return None
    return number if number > 0 else None


def string_list(tags: dict, key: str) -> list[str]:
    value = tags.get(key)
    if isinstance(value, str):
        value = [value]
    if not isinstance(value, list):
        return []
    items = []
    for item in value:
        rendered = str(item).strip()
        if rendered and rendered not in items:
            items.append(rendered)
    return items


def pair(number: int | None, total: int | None) -> str | None:
    if number is None:
        return None
    if total is None:
        return str(number)
    return f"{number}/{total}"


def lrc_rows(synced: str | None) -> list[tuple[str, int]]:
    if not synced:
        return []
    rows: list[tuple[str, int]] = []
    pattern = re.compile(r"\[(\d+):(\d+)(?:\.(\d+))?\](.*)")
    for line in synced.splitlines():
        match = pattern.match(line.strip())
        if match is None:
            continue
        minutes = int(match.group(1))
        seconds = int(match.group(2))
        frac = match.group(3) or "0"
        if len(frac) <= 2:
            millis = int(frac.ljust(2, "0")) * 10
        else:
            millis = int(frac[:3].ljust(3, "0"))
        total = minutes * 60000 + seconds * 1000 + millis
        lyric = match.group(4).strip()
        if lyric:
            rows.append((lyric, total))
    return rows


def apply_mp3(path: str, tags: dict, cover: bytes | None, cover_mime: str, lyrics: dict) -> None:
    from mutagen.id3 import (
        APIC,
        COMM,
        SYLT,
        TALB,
        TCOM,
        TCON,
        TDRC,
        TIT2,
        TPE1,
        TPE2,
        TPOS,
        TRCK,
        TSRC,
        TXXX,
        USLT,
        Encoding,
        ID3,
        ID3NoHeaderError,
    )

    try:
        frames = ID3(path)
    except ID3NoHeaderError:
        frames = ID3()

    kept_pictures = []
    if not cover:
        for frame in frames.getall("APIC"):
            kept_pictures.append(
                APIC(
                    encoding=frame.encoding,
                    mime=frame.mime,
                    type=frame.type,
                    desc=frame.desc,
                    data=bytes(frame.data),
                )
            )

    frames.clear()

    title = text(tags, "title")
    if title:
        frames.add(TIT2(encoding=Encoding.UTF8, text=title))
    artist = text(tags, "artist")
    if artist:
        frames.add(TPE1(encoding=Encoding.UTF8, text=artist))
    album_artist = text(tags, "album_artist")
    if album_artist:
        frames.add(TPE2(encoding=Encoding.UTF8, text=album_artist))
    album = text(tags, "album")
    if album:
        frames.add(TALB(encoding=Encoding.UTF8, text=album))
    track = pair(integer(tags, "track_number"), integer(tags, "track_total"))
    if track:
        frames.add(TRCK(encoding=Encoding.UTF8, text=track))
    disc = pair(integer(tags, "disc_number"), integer(tags, "disc_total"))
    if disc:
        frames.add(TPOS(encoding=Encoding.UTF8, text=disc))
    date = text(tags, "date")
    if date:
        frames.add(TDRC(encoding=Encoding.UTF8, text=date))
    genres = string_list(tags, "genres")
    if genres:
        frames.add(TCON(encoding=Encoding.UTF8, text=", ".join(genres)))
    composers = string_list(tags, "composers")
    if composers:
        frames.add(TCOM(encoding=Encoding.UTF8, text=composers))
    isrc = text(tags, "isrc")
    if isrc:
        frames.add(TSRC(encoding=Encoding.UTF8, text=isrc))
    deezer_id = text(tags, "deezer_track_id")
    if deezer_id:
        frames.add(TXXX(encoding=Encoding.UTF8, desc="Deezer Track ID", text=deezer_id))
        frames.add(COMM(encoding=Encoding.UTF8, lang="eng", desc="", text=f"Deezer:{deezer_id}"))

    plain = text(lyrics, "plain")
    if plain:
        frames.add(USLT(encoding=Encoding.UTF8, lang="eng", desc="", text=plain))
    synced_rows = lrc_rows(text(lyrics, "synced"))
    if synced_rows:
        frames.add(SYLT(encoding=Encoding.UTF8, lang="eng", format=2, type=1, desc="", text=synced_rows))

    if cover:
        frames.add(APIC(encoding=Encoding.UTF8, mime=cover_mime or "image/jpeg", type=3, desc="Cover", data=cover))
    else:
        for frame in kept_pictures:
            frames.add(frame)

    frames.save(path)


def apply_flac(path: str, tags: dict, cover: bytes | None, cover_mime: str, lyrics: dict) -> None:
    from mutagen.flac import FLAC, Picture

    audio = FLAC(path)
    kept_pictures: list[Picture] = []
    if not cover:
        for pic in list(audio.pictures):
            clone = Picture()
            clone.type = pic.type
            clone.mime = pic.mime
            clone.desc = pic.desc
            clone.width = pic.width
            clone.height = pic.height
            clone.depth = pic.depth
            clone.colors = pic.colors
            clone.data = bytes(pic.data)
            kept_pictures.append(clone)

    audio.delete()
    audio.clear_pictures()

    mapping = {
        "TITLE": text(tags, "title"),
        "ARTIST": text(tags, "artist"),
        "ALBUMARTIST": text(tags, "album_artist"),
        "ALBUM": text(tags, "album"),
        "DATE": text(tags, "date"),
        "ISRC": text(tags, "isrc"),
        "DEEZER_TRACK_ID": text(tags, "deezer_track_id"),
        "LYRICS": text(lyrics, "plain"),
        "SYNCEDLYRICS": text(lyrics, "synced"),
    }
    track_number = integer(tags, "track_number")
    track_total = integer(tags, "track_total")
    disc_number = integer(tags, "disc_number")
    disc_total = integer(tags, "disc_total")
    if track_number:
        mapping["TRACKNUMBER"] = str(track_number)
    if track_total:
        mapping["TRACKTOTAL"] = str(track_total)
        mapping["TOTALTRACKS"] = str(track_total)
    if disc_number:
        mapping["DISCNUMBER"] = str(disc_number)
    if disc_total:
        mapping["DISCTOTAL"] = str(disc_total)

    for key, value in mapping.items():
        if value:
            audio[key] = value

    genres = string_list(tags, "genres")
    if genres:
        audio["GENRE"] = genres
    composers = string_list(tags, "composers")
    if composers:
        audio["COMPOSER"] = composers

    if cover:
        picture = Picture()
        picture.type = 3
        picture.mime = cover_mime or "image/jpeg"
        picture.desc = "Cover"
        picture.data = cover
        audio.add_picture(picture)
    else:
        for picture in kept_pictures:
            audio.add_picture(picture)

    audio.save()


def apply_mp4(path: str, tags: dict, cover: bytes | None, cover_mime: str, lyrics: dict) -> None:
    from mutagen.mp4 import MP4, MP4Cover

    audio = MP4(path)
    kept_covers = None
    if not cover and audio.tags is not None and "covr" in audio.tags:
        kept_covers = [
            MP4Cover(bytes(item), imageformat=getattr(item, "imageformat", MP4Cover.FORMAT_JPEG))
            for item in audio.tags["covr"]
        ]

    audio.clear()

    def put(key: str, value: str | None) -> None:
        if value:
            audio[key] = [value]

    put("\xa9nam", text(tags, "title"))
    put("\xa9ART", text(tags, "artist"))
    put("aART", text(tags, "album_artist"))
    put("\xa9alb", text(tags, "album"))
    put("\xa9day", text(tags, "date"))
    genres = string_list(tags, "genres")
    if genres:
        audio["\xa9gen"] = [", ".join(genres)]
    composers = string_list(tags, "composers")
    if composers:
        audio["\xa9wrt"] = composers
    isrc = text(tags, "isrc")
    if isrc:
        audio["----:com.apple.iTunes:ISRC"] = [isrc.encode("utf-8")]
    deezer_id = text(tags, "deezer_track_id")
    if deezer_id:
        audio["----:com.apple.iTunes:Deezer Track ID"] = [deezer_id.encode("utf-8")]

    track_number = integer(tags, "track_number")
    if track_number:
        audio["trkn"] = [(track_number, integer(tags, "track_total") or 0)]
    disc_number = integer(tags, "disc_number")
    if disc_number:
        audio["disk"] = [(disc_number, integer(tags, "disc_total") or 0)]

    plain = text(lyrics, "plain") or text(lyrics, "synced")
    put("\xa9lyr", plain)

    if cover:
        imageformat = MP4Cover.FORMAT_PNG if "png" in (cover_mime or "") else MP4Cover.FORMAT_JPEG
        audio["covr"] = [MP4Cover(cover, imageformat=imageformat)]
    elif kept_covers:
        audio["covr"] = kept_covers

    audio.save()


if __name__ == "__main__":
    sys.exit(main())
