#!/usr/bin/env bash
# plugins/dist/ 에 배포용 zip과 version.json을 만든다.
# 플러그인 헤더의 Version이 version.json과 같으면 아무것도 하지 않는다.
#
#   bash tools/build_plugin_dist.sh
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"

SRC="plugins/github-image-gallery"
DIST="plugins/dist"
SLUG="github-image-gallery"

VER=$(grep -m1 '^ \* Version:' "$SRC/$SLUG.php" | sed 's/.*Version: *//' | tr -d '[:space:]')
if [ -z "$VER" ]; then
  echo "::error::플러그인 헤더에서 Version을 찾지 못했습니다"
  exit 1
fi

CUR=""
if [ -f "$DIST/version.json" ]; then
  CUR=$(sed -n 's/.*"version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$DIST/version.json" | head -1)
fi

if [ "$VER" = "$CUR" ] && [ -f "$DIST/$SLUG.zip" ]; then
  echo "version $VER unchanged, nothing to build"
  exit 0
fi

rm -rf build
mkdir -p build "$DIST"
cp -r "$SRC" "build/$SLUG"
( cd build && zip -rqX "$SLUG.zip" "$SLUG" )
mv "build/$SLUG.zip" "$DIST/$SLUG.zip"
rm -rf build

printf '{\n  "version": "%s",\n  "updated": "%s"\n}\n' \
  "$VER" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$DIST/version.json"

echo "built $DIST/$SLUG.zip for version $VER"
