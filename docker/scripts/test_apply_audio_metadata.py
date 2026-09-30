#!/usr/bin/env python3
"""Round-trip tags through apply-audio-metadata.py when mutagen is installed."""

from __future__ import annotations

import base64
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).with_name("apply-audio-metadata.py")


def mutagen_available() -> bool:
    try:
        import mutagen  # noqa: F401
    except ImportError:
        return False
    return True


class ApplyAudioMetadataTest(unittest.TestCase):
    def test_mp3_tags_round_trip(self) -> None:
        if not mutagen_available():
            self.skipTest("mutagen is not installed")

        frame = bytes.fromhex("FFFB9000") + (b"\x00" * 413)
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "track.mp3"
            path.write_bytes(frame * 2)
            payload = {
                "path": str(path),
                "tags": {
                    "title": "Harder Better Faster Stronger",
                    "artist": "Daft Punk feat. Pharrell",
                    "album_artist": "Daft Punk",
                    "album": "Discovery",
                    "track_number": 4,
                    "track_total": 14,
                    "disc_number": 1,
                    "date": "2001-03-12",
                    "genres": ["Dance"],
                    "composers": ["Thomas Bangalter"],
                    "isrc": "GBDUW0000059",
                    "deezer_track_id": "3135556",
                },
                "cover": None,
                "lyrics": {"plain": "Work it", "synced": "[00:01.50]Work it"},
            }
            completed = subprocess.run(
                [sys.executable, str(SCRIPT)],
                input=json.dumps(payload),
                text=True,
                capture_output=True,
                check=False,
            )
            self.assertEqual(completed.returncode, 0, completed.stderr)

            from mutagen.id3 import ID3

            tags = ID3(path)
            self.assertEqual(str(tags.getall("TIT2")[0]), "Harder Better Faster Stronger")
            self.assertEqual(str(tags.getall("TPE1")[0]), "Daft Punk feat. Pharrell")
            self.assertEqual(str(tags.getall("TALB")[0]), "Discovery")
            self.assertEqual(str(tags.getall("TRCK")[0]), "4/14")
            self.assertEqual(str(tags.getall("TCOM")[0]), "Thomas Bangalter")
            self.assertEqual(tags.getall("USLT")[0].text, "Work it")
            self.assertTrue(tags.getall("SYLT"))

    def test_preserves_existing_cover_when_omitted(self) -> None:
        if not mutagen_available():
            self.skipTest("mutagen is not installed")

        original = b"\xff\xd8\xff\xd9"
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "track.mp3"
            self.write_mp3(path)
            self.apply(path, {"title": "Original"}, original)
            self.apply(path, {"title": "Updated"}, None)

            from mutagen.id3 import ID3

            tags = ID3(path)
            pictures = tags.getall("APIC")
            self.assertEqual(str(tags.getall("TIT2")[0]), "Updated")
            self.assertEqual(len(pictures), 1)
            self.assertEqual(bytes(pictures[0].data), original)

    def test_replaces_cover_when_provided(self) -> None:
        if not mutagen_available():
            self.skipTest("mutagen is not installed")

        original = b"\xff\xd8old"
        replacement = b"\xff\xd8new"
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "track.mp3"
            self.write_mp3(path)
            self.apply(path, {"title": "Original"}, original)
            self.apply(path, {"title": "Original"}, replacement)

            from mutagen.id3 import ID3

            pictures = ID3(path).getall("APIC")
            self.assertEqual(len(pictures), 1)
            self.assertEqual(bytes(pictures[0].data), replacement)

    def test_has_cover_flag_reports_embedded_artwork(self) -> None:
        if not mutagen_available():
            self.skipTest("mutagen is not installed")

        jpeg = b"\xff\xd8\xff\xd9"
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "track.mp3"
            self.write_mp3(path)
            self.apply(path, {"title": "Original"}, None)
            self.assertFalse(self.has_cover(path))
            self.apply(path, {"title": "Original"}, jpeg)
            self.assertTrue(self.has_cover(path))

    def write_mp3(self, path: Path) -> None:
        frame = bytes.fromhex("FFFB9000") + (b"\x00" * 413)
        path.write_bytes(frame * 2)

    def apply(self, path: Path, tags: dict, cover: bytes | None) -> None:
        payload = {
            "path": str(path),
            "tags": tags,
            "cover": base64.b64encode(cover).decode("ascii") if cover else None,
            "cover_mime": "image/jpeg",
            "lyrics": {},
        }
        completed = subprocess.run(
            [sys.executable, str(SCRIPT)],
            input=json.dumps(payload),
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(completed.returncode, 0, completed.stderr)

    def has_cover(self, path: Path) -> bool:
        completed = subprocess.run(
            [sys.executable, str(SCRIPT), "--has-cover", str(path)],
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(completed.returncode, 0, completed.stderr)
        return completed.stdout.strip() == "yes"


if __name__ == "__main__":
    unittest.main()
