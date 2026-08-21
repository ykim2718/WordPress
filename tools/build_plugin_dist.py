#!/usr/bin/env python3
"""Build plugins/dist/ : the plugin zip plus version.json.

version.json carries the version the site compares against, and the two
documents WordPress shows in its "View details" window -- the description and
the changelog -- rendered from DESCRIPTION.md and CHANGELOG.md. Screenshot
paths in the description are rewritten to raw.githubusercontent.com so the
images load inside that window.

Nothing is rebuilt unless the version in the plugin header actually changed.

    python3 tools/build_plugin_dist.py [--force]
"""

from __future__ import annotations

import argparse
import json
import re
import shutil
import subprocess
import sys
import zipfile
from datetime import datetime, timezone
from pathlib import Path

SLUG = "github-image-gallery"
REPO = "ykim2718/WordPress"
BRANCH = "main"
PLUGIN = f"plugins/{SLUG}"
SHOTS = f"https://raw.githubusercontent.com/{REPO}/{BRANCH}/{PLUGIN}/screenshots/"


def repo_root() -> Path:
    out = subprocess.run(["git", "rev-parse", "--show-toplevel"],
                         capture_output=True, text=True, check=True)
    return Path(out.stdout.strip())


def header_version(php: Path) -> str:
    m = re.search(r"^\s*\*\s*Version:\s*(\S+)", php.read_text(encoding="utf-8"), re.M)
    if not m:
        sys.exit("could not find Version: in the plugin header")
    return m.group(1)


# ---------------------------------------------------------------- markdown --
# 이 저장소가 쓰는 문법만 다룬다: 제목, 목록, 문단, 굵게, 코드, 링크, 그림.

def inline(text: str) -> str:
    text = (text.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;"))
    text = re.sub(r"!\[([^\]]*)\]\(([^)]+)\)",
                  lambda m: '<img src="%s" alt="%s" style="max-width:100%%;height:auto;'
                            'border:1px solid #ddd;border-radius:6px" />'
                            % (m.group(2) if "://" in m.group(2) else SHOTS + m.group(2),
                               m.group(1)),
                  text)
    text = re.sub(r"\[([^\]]+)\]\(([^)]+)\)", r'<a href="\2">\1</a>', text)
    text = re.sub(r"\*\*([^*]+)\*\*", r"<strong>\1</strong>", text)
    text = re.sub(r"\*([^*]+)\*", r"<em>\1</em>", text)
    text = re.sub(r"`([^`]+)`", r"<code>\1</code>", text)
    return text


def md_to_html(md: str) -> str:
    out, block, mode = [], [], None

    def flush():
        nonlocal block, mode
        if not block:
            return
        if mode == "ul":
            out.append("<ul>" + "".join("<li>%s</li>" % inline(b) for b in block) + "</ul>")
        elif mode == "pre":
            body = "\n".join(block)
            out.append("<pre><code>%s</code></pre>"
                       % body.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;"))
        else:
            out.append("<p>%s</p>" % inline(" ".join(block)))
        block, mode = [], None

    fenced = False
    for raw in md.splitlines():
        line = raw.rstrip()

        if line.startswith("```"):
            if fenced:
                flush()
            else:
                flush()
                mode = "pre"
            fenced = not fenced
            continue
        if fenced:
            block.append(raw)
            continue

        if not line.strip():
            flush()
            continue
        if line.startswith("#"):
            flush()
            level = len(line) - len(line.lstrip("#"))
            out.append("<h%d>%s</h%d>" % (min(level + 1, 6), inline(line.lstrip("# ")),
                                          min(level + 1, 6)))
            continue
        if line.lstrip().startswith("- "):
            if mode != "ul":
                flush()
                mode = "ul"
            block.append(line.lstrip()[2:])
            continue

        if mode == "ul":                     # 목록 항목이 다음 줄로 이어지는 경우
            block[-1] += " " + line.strip()
            continue
        if mode is None:
            mode = "p"
        block.append(line.strip())

    flush()
    return "\n".join(out)


# -------------------------------------------------------------------- build --

def build(root: Path, force: bool) -> int:
    home = root / PLUGIN
    src  = home / "src"          # zip에 들어가는 것만 여기 둔다
    dist = home / "dist"
    ver  = header_version(src / f"{SLUG}.php")

    current = ""
    vj = dist / "version.json"
    if vj.exists():
        try:
            current = json.loads(vj.read_text(encoding="utf-8")).get("version", "")
        except Exception:
            current = ""

    zip_path = dist / f"{SLUG}.zip"
    if not force and ver == current and zip_path.exists():
        print(f"version {ver} unchanged, nothing to build")
        return 0

    dist.mkdir(parents=True, exist_ok=True)

    # src/ 아래만 압축한다. 문서와 스크린샷, dist는 자연히 빠진다.
    if zip_path.exists():
        zip_path.unlink()
    with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED) as z:
        for p in sorted(src.rglob("*")):
            if p.is_dir():
                continue
            z.write(p, f"{SLUG}/{p.relative_to(src).as_posix()}")

    payload = {
        "version": ver,
        "updated": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "description": md_to_html((home / "DESCRIPTION.md").read_text(encoding="utf-8")),
        "changelog": md_to_html((home / "CHANGELOG.md").read_text(encoding="utf-8")),
    }
    vj.write_text(json.dumps(payload, indent=1, ensure_ascii=False) + "\n", encoding="utf-8")

    print(f"built {zip_path.relative_to(root)} for version {ver} "
          f"({zip_path.stat().st_size} bytes), wrote {vj.relative_to(root)}")
    return 0


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--force", action="store_true", help="rebuild even if the version is unchanged")
    args = ap.parse_args()
    return build(repo_root(), args.force)


if __name__ == "__main__":
    raise SystemExit(main())
