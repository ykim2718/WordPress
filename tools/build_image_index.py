#!/usr/bin/env python3
"""Build Images/index.json and Images/.thumbs/ for the github_image_gallery plugin.

The plugin reads the two artefacts this script writes, so at runtime it needs a
single static file from raw.githubusercontent.com instead of a pile of
api.github.com calls. That removes the 60-per-hour rate limit from the picture
and lets the gallery load 30 KB thumbnails rather than 400 KB originals.

Run from anywhere inside the repository:

    python3 tools/build_image_index.py                 # Images/
    python3 tools/build_image_index.py --dir Photos    # some other folder
"""

from __future__ import annotations

import argparse
import hashlib
import json
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

from PIL import Image

SOURCE_EXT = {".jpg", ".jpeg", ".png", ".gif", ".webp", ".avif", ".bmp"}
SKIP_EXT = {".svg"}          # listed in the index, but no raster thumbnail
THUMB_DIR = ".thumbs"
EXIF_TAKEN = 36867           # DateTimeOriginal


def repo_root() -> Path:
    out = subprocess.run(
        ["git", "rev-parse", "--show-toplevel"],
        capture_output=True, text=True, check=True,
    )
    return Path(out.stdout.strip())


def commit_epoch(path: Path, root: Path) -> int:
    """Unix time of the newest commit that touched the file, 0 if unknown."""
    out = subprocess.run(
        ["git", "log", "-1", "--format=%ct", "--", str(path.relative_to(root))],
        capture_output=True, text=True, cwd=root,
    )
    raw = out.stdout.strip()
    return int(raw) if raw.isdigit() else 0


def exif_epoch(im: Image.Image) -> int:
    try:
        taken = (im.getexif() or {}).get(EXIF_TAKEN)
        if not taken:
            return 0
        return int(datetime.strptime(str(taken), "%Y:%m:%d %H:%M:%S")
                   .replace(tzinfo=timezone.utc).timestamp())
    except Exception:
        return 0


def sha1_of(path: Path) -> str:
    h = hashlib.sha1()
    with path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def build(folder: Path, root: Path, width: int, quality: int) -> dict:
    thumbs = folder / THUMB_DIR
    thumbs.mkdir(exist_ok=True)

    index_path = folder / "index.json"
    previous = {}
    if index_path.exists():
        try:
            old = json.loads(index_path.read_text(encoding="utf-8"))
            previous = {it["n"]: it for it in old.get("items", [])}
        except Exception:
            previous = {}

    items, kept, made, wanted = [], 0, 0, set()

    for src in sorted(folder.iterdir(), key=lambda p: p.name.lower()):
        if not src.is_file():
            continue
        ext = src.suffix.lower()
        if ext not in SOURCE_EXT and ext not in SKIP_EXT:
            continue

        digest = sha1_of(src)
        entry = {
            "n": src.name,
            "s": src.stat().st_size,
            "h1": digest,
            "d": commit_epoch(src, root),
        }

        if ext in SKIP_EXT:
            entry.update({"w": 0, "ht": 0, "t": "", "e": 0})
            items.append(entry)
            continue

        # keep the extension in the thumbnail name: foo.jpg and foo.webp are
        # two different pictures and must not collapse onto one thumbnail
        thumb = thumbs / (src.name + ".webp")
        wanted.add(thumb.name)
        old = previous.get(src.name)

        if old and old.get("h1") == digest and thumb.exists():
            # source unchanged and the thumbnail is still there: reuse it
            entry.update({"w": old.get("w", 0), "ht": old.get("ht", 0),
                          "t": f"{THUMB_DIR}/{thumb.name}", "e": old.get("e", 0)})
            kept += 1
        else:
            with Image.open(src) as im:
                entry["w"], entry["ht"] = im.size
                entry["e"] = exif_epoch(im)
                im = im.convert("RGBA" if im.mode in ("RGBA", "LA", "P") else "RGB")
                im.thumbnail((width, width * 4), Image.LANCZOS)
                im.save(thumb, "WEBP", quality=quality, method=6)
            entry["t"] = f"{THUMB_DIR}/{thumb.name}"
            made += 1

        items.append(entry)

    removed = 0
    for stale in thumbs.glob("*.webp"):        # thumbnails whose source is gone
        if stale.name not in wanted:
            stale.unlink()
            removed += 1

    payload = {
        "generated": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "folder": str(folder.relative_to(root)).replace("\\", "/"),
        "thumb_dir": THUMB_DIR,
        "thumb_width": width,
        "count": len(items),
        "items": items,
    }
    index_path.write_text(json.dumps(payload, indent=1, ensure_ascii=False) + "\n",
                          encoding="utf-8")

    print(f"{len(items)} images | thumbnails {made} new, {kept} reused, {removed} pruned")
    print(f"wrote {index_path.relative_to(root)}")
    return payload


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--dir", default="Images", help="folder to index (default: Images)")
    ap.add_argument("--width", type=int, default=480, help="thumbnail width in px")
    ap.add_argument("--quality", type=int, default=78, help="WebP quality")
    args = ap.parse_args()

    root = repo_root()
    folder = (root / args.dir).resolve()
    if not folder.is_dir():
        print(f"no such folder: {folder}", file=sys.stderr)
        return 1

    build(folder, root, args.width, args.quality)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
