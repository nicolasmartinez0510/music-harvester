#!/usr/bin/env python3
"""Compose a square playlist cover from a JSON spec on stdin."""

from __future__ import annotations

import hashlib
import io
import json
import os
import sys
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

SIZE = 1000
FONT_CANDIDATES = [
    "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
    "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf",
    "/Library/Fonts/Arial Bold.ttf",
    "/Library/Fonts/Arial.ttf",
    "/System/Library/Fonts/Supplemental/Arial Bold.ttf",
    "/System/Library/Fonts/Supplemental/Arial.ttf",
    "/System/Library/Fonts/Supplemental/Arial Unicode.ttf",
]


def main() -> int:
    try:
        spec = json.load(sys.stdin)
    except json.JSONDecodeError as exc:
        print(f"invalid json: {exc}", file=sys.stderr)
        return 2

    if not isinstance(spec, dict):
        print("invalid spec", file=sys.stderr)
        return 2

    try:
        result = render(spec)
    except Exception as exc:
        print(str(exc), file=sys.stderr)
        return 1

    json.dump(result, sys.stdout)
    sys.stdout.write("\n")
    return 0


def render(spec: dict) -> dict:
    mode = spec.get("mode")
    output = spec.get("output")
    if not isinstance(output, str) or output == "":
        raise ValueError("missing output")

    size = int(spec.get("size") or SIZE)
    title = spec.get("title") if isinstance(spec.get("title"), str) and spec.get("title") else "Playlist"
    playlist_id = int(spec.get("playlist_id") or 0)
    Path(output).parent.mkdir(parents=True, exist_ok=True)

    if mode == "title":
        image = title_card(title, playlist_id, size)
        save_jpeg(image, output)
        return {"ok": True, "images": 0, "reason": None}

    if mode == "upload":
        paths = string_list(spec.get("image_paths"))
        if not paths:
            return {"ok": False, "images": 0, "reason": "no_images"}
        image = cover_crop(Image.open(paths[0]), size, size)
        save_jpeg(image, output)
        return {"ok": True, "images": 1, "reason": None}

    if mode == "mosaic":
        images = unique_audio_covers(string_list(spec.get("audio_paths")))
        if not images:
            return {"ok": False, "images": 0, "reason": "no_images"}
        save_jpeg(compose_grid(images, size), output)
        return {"ok": True, "images": len(images), "reason": None}

    if mode in {"portrait", "grid"}:
        images = []
        for path in string_list(spec.get("image_paths")):
            try:
                images.append(Image.open(path))
            except OSError:
                continue
            if len(images) == 4:
                break
        if not images:
            return {"ok": False, "images": 0, "reason": "no_images"}
        image = compose_grid(images, size)
        if mode == "portrait":
            image = with_title(image, title, anchor="top")
        save_jpeg(image, output)
        return {"ok": True, "images": len(images), "reason": None}

    raise ValueError(f"unknown mode: {mode}")


def unique_audio_covers(paths: list[str]) -> list[Image.Image]:
    seen: set[str] = set()
    images: list[Image.Image] = []
    for path in paths:
        data = extract_cover(path)
        if not data:
            continue
        digest = hashlib.sha256(data).hexdigest()
        if digest in seen:
            continue
        seen.add(digest)
        try:
            images.append(Image.open(io.BytesIO(data)))
        except OSError:
            continue
        if len(images) == 4:
            break
    return images


def extract_cover(path: str) -> bytes | None:
    suffix = Path(path).suffix.lower()
    try:
        if suffix == ".mp3":
            from mutagen.id3 import ID3

            tags = ID3(path)
            for frame in tags.getall("APIC"):
                if frame.data:
                    return bytes(frame.data)
        elif suffix == ".flac":
            from mutagen.flac import FLAC

            audio = FLAC(path)
            if audio.pictures and audio.pictures[0].data:
                return bytes(audio.pictures[0].data)
        elif suffix in {".m4a", ".mp4", ".aac"}:
            from mutagen.mp4 import MP4

            audio = MP4(path)
            covr = audio.tags.get("covr") if audio.tags else None
            if covr:
                return bytes(covr[0])
    except Exception:
        return None
    return None


def compose_grid(images: list[Image.Image], size: int) -> Image.Image:
    canvas = Image.new("RGB", (size, size), (12, 12, 12))
    boxes = layout_boxes(len(images), size)
    for image, (left, top, width, height) in zip(images, boxes):
        canvas.paste(cover_crop(image, width, height), (left, top))
    return canvas


def layout_boxes(count: int, size: int) -> list[tuple[int, int, int, int]]:
    if count <= 1:
        return [(0, 0, size, size)]
    half = size // 2
    rest = size - half
    if count == 2:
        return [(0, 0, half, size), (half, 0, rest, size)]
    if count == 3:
        return [
            (0, 0, half, size),
            (half, 0, rest, half),
            (half, half, rest, rest),
        ]
    return [
        (0, 0, half, half),
        (half, 0, rest, half),
        (0, half, half, rest),
        (half, half, rest, rest),
    ]


def cover_crop(image: Image.Image, width: int, height: int) -> Image.Image:
    image = image.convert("RGB")
    src_w, src_h = image.size
    if src_w < 1 or src_h < 1 or width < 1 or height < 1:
        return Image.new("RGB", (max(1, width), max(1, height)), (20, 20, 20))
    scale = max(width / src_w, height / src_h)
    resized = image.resize(
        (max(1, round(src_w * scale)), max(1, round(src_h * scale))),
        Image.Resampling.LANCZOS,
    )
    left = max(0, (resized.width - width) // 2)
    top = max(0, (resized.height - height) // 2)
    return resized.crop((left, top, left + width, top + height))


def title_card(title: str, playlist_id: int, size: int) -> Image.Image:
    start, end = palette(playlist_id)
    column = Image.new("RGB", (1, size))
    pixels = column.load()
    for y in range(size):
        blend = y / max(1, size - 1)
        pixels[0, y] = tuple(
            round(start[channel] + (end[channel] - start[channel]) * blend) for channel in range(3)
        )
    image = column.resize((size, size), Image.Resampling.BILINEAR)
    return with_title(image, title, anchor="center")


def with_title(image: Image.Image, title: str, anchor: str) -> Image.Image:
    base = image.convert("RGBA")
    if anchor == "top":
        base = fade_top(base)
    layout = layout_title(title, base.width, anchor)
    draw = ImageDraw.Draw(base)
    font = load_font(layout["font_size"])
    y = layout["top"]
    for line in layout["lines"]:
        width = draw.textlength(line, font=font)
        x = (base.width - width) / 2
        draw.text((x + 2, y + 2), line, font=font, fill=(0, 0, 0, 150))
        draw.text((x, y), line, font=font, fill=(255, 255, 255, 255))
        y += layout["line_height"]
    return base.convert("RGB")


def layout_title(title: str, size: int, anchor: str) -> dict:
    margin = 64
    max_width = size - margin * 2
    max_height = size // 2 if anchor == "top" else size - margin * 2
    probe = ImageDraw.Draw(Image.new("RGB", (1, 1)))
    font_size = 92
    lines = [title]
    line_height = font_size
    while font_size >= 28:
        font = load_font(font_size)
        lines = wrap_text(title, font, max_width, probe)
        line_height = font_size + max(8, font_size // 6)
        block_height = line_height * len(lines)
        widest = max((probe.textlength(line, font=font) for line in lines), default=0)
        if len(lines) <= 3 and block_height <= max_height and widest <= max_width:
            break
        font_size -= 4

    block_height = line_height * len(lines)
    if anchor == "top":
        top = margin
    else:
        top = max(margin, (size - block_height) // 2)
    bottom = top + block_height
    return {
        "lines": lines,
        "font_size": font_size,
        "line_height": line_height,
        "top": top,
        "bottom": bottom,
        "left": margin,
        "right": size - margin,
    }


def wrap_text(text: str, font: ImageFont.ImageFont, max_width: int, draw: ImageDraw.ImageDraw) -> list[str]:
    words = text.split()
    if not words:
        return ["Playlist"]
    lines: list[str] = []
    current = words[0]
    for word in words[1:]:
        trial = f"{current} {word}"
        if draw.textlength(trial, font=font) <= max_width:
            current = trial
        else:
            lines.append(current)
            current = word
    lines.append(current)
    return lines


def fade_top(image: Image.Image) -> Image.Image:
    width, height = image.size
    fade = Image.new("L", (1, height), 0)
    limit = max(1, int(height * 0.46))
    for y in range(height):
        if y < limit:
            alpha = int(170 * (1 - y / limit))
        else:
            alpha = 0
        fade.putpixel((0, y), alpha)
    fade = fade.resize((width, height))
    shade = Image.new("RGBA", image.size, (0, 0, 0, 255))
    shade.putalpha(fade)
    image.alpha_composite(shade)
    return image


def palette(playlist_id: int) -> tuple[tuple[int, int, int], tuple[int, int, int]]:
    digest = hashlib.sha256(str(playlist_id).encode()).digest()
    hue = digest[0] / 255 * 360
    return hsl_to_rgb(hue, 0.55, 0.28), hsl_to_rgb((hue + 36) % 360, 0.6, 0.46)


def hsl_to_rgb(hue: float, saturation: float, lightness: float) -> tuple[int, int, int]:
    chroma = (1 - abs(2 * lightness - 1)) * saturation
    sector = hue / 60
    secondary = chroma * (1 - abs(sector % 2 - 1))
    match = lightness - chroma / 2
    rgb = {
        0: (chroma, secondary, 0),
        1: (secondary, chroma, 0),
        2: (0, chroma, secondary),
        3: (0, secondary, chroma),
        4: (secondary, 0, chroma),
        5: (chroma, 0, secondary),
    }[int(sector) % 6]
    return tuple(round((channel + match) * 255) for channel in rgb)


def load_font(size: int) -> ImageFont.ImageFont:
    for path in FONT_CANDIDATES:
        if os.path.isfile(path):
            return ImageFont.truetype(path, size)
    return ImageFont.load_default()


def save_jpeg(image: Image.Image, output: str) -> None:
    image.convert("RGB").save(output, "JPEG", quality=85, optimize=True)


def string_list(value: object) -> list[str]:
    if not isinstance(value, list):
        return []
    return [item for item in value if isinstance(item, str) and item != ""]


if __name__ == "__main__":
    raise SystemExit(main())
