#!/usr/bin/env bash
# 始终使用 POC 专用 .venv，避免 conda/base 环境缺少 scrapling。
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"

VENV_PYTHON="$ROOT_DIR/.venv/bin/python"

if [[ ! -x "$VENV_PYTHON" ]]; then
  echo "未找到 .venv，请先执行: ./scripts/setup.sh" >&2
  exit 1
fi

exec "$VENV_PYTHON" -m geo_monitor_poc "$@"
