#!/usr/bin/env bash
# 실제 작업은 파이썬 쪽에 있다. 이 파일은 기존 호출 경로를 유지하기 위한 껍데기다.
set -euo pipefail
exec python3 "$(dirname "$0")/build_plugin_dist.py" "$@"
