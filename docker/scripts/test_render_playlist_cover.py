#!/usr/bin/env python3
"""Cover layout: accented titles stay inside the square, and one artwork is not tiled."""

from __future__ import annotations

import importlib.util
import io
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).with_name("render-playlist-cover.py")


def load_module():
    spec = importlib.util.spec_from_file_location("render_playlist_cover", SCRIPT)
    if spec is None or spec.loader is None:
        raise RuntimeError("cannot load cover script")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def pillow_available() -> bool:
    try:
        import PIL  # noqa: F401
    except ImportError:
        return False
    return True


def mutagen_available() -> bool:
    try:
        import mutagen  # noqa: F401
    except ImportError:
        return False
    return True


class RenderPlaylistCoverTest(unittest.TestCase):
    def test_accented_title_fits_inside_the_square(self) -> None:
        if not pillow_available():
            self.skipTest("pillow is not installed")

        module = load_module()
        layout = module.layout_title("Reggaetón viejo", 1000, "center")
        self.assertGreater(layout["top"], 0)
        self.assertLess(layout["bottom"], 1000)
        self.assertTrue(any("ó" in line for line in layout["lines"]))

        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / "cover.jpg"
            result = module.render({
                "mode": "title",
                "title": "Reggaetón viejo",
                "playlist_id": 4,
                "output": str(output),
                "size": 1000,
            })
            self.assertTrue(result["ok"])
            from PIL import Image

            with Image.open(output) as image:
                self.assertEqual(image.size, (1000, 1000))

    def test_one_unique_cover_is_not_repeated_four_times(self) -> None:
        if not pillow_available() or not mutagen_available():
            self.skipTest("pillow and mutagen are required")

        module = load_module()
        cover = split_jpeg()
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            first = root / "a.mp3"
            second = root / "b.mp3"
            write_mp3(first, cover)
            write_mp3(second, cover)
            output = root / "cover.jpg"
            result = module.render({
                "mode": "mosaic",
                "title": "Country",
                "playlist_id": 1,
                "output": str(output),
                "audio_paths": [str(first), str(second)],
                "size": 200,
            })
            self.assertTrue(result["ok"])
            self.assertEqual(result["images"], 1)

            from PIL import Image

            with Image.open(output) as image:
                red = image.getpixel((80, 100))
                blue = image.getpixel((140, 100))
            self.assertGreater(red[0], red[2])
            self.assertGreater(blue[2], blue[0])


def split_jpeg() -> bytes:
    from PIL import Image

    image = Image.new("RGB", (40, 40), (0, 0, 220))
    for x in range(20):
        for y in range(40):
            image.putpixel((x, y), (220, 0, 0))
    buffer = io.BytesIO()
    image.save(buffer, "JPEG", quality=95)
    return buffer.getvalue()


def write_mp3(path: Path, cover: bytes) -> None:
    frame = bytes.fromhex("FFFB9000") + (b"\x00" * 413)
    path.write_bytes(frame * 2)
    from mutagen.id3 import APIC, ID3, TIT2, Encoding

    tags = ID3()
    tags.add(TIT2(encoding=Encoding.UTF8, text="Song"))
    tags.add(APIC(encoding=Encoding.UTF8, mime="image/jpeg", type=3, desc="Cover", data=cover))
    tags.save(path)


if __name__ == "__main__":
    unittest.main()
