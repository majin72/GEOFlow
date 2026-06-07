#!/bin/sh
# Sidecar 容器入口：先启动 noVNC 栈，再 exec sidecar API（避免 shell && 导致重启时重复拉起 X）。
set -eu

cd /app

./scripts/novnc/start-novnc.sh

exec python -m geo_monitor_poc serve \
  --host 0.0.0.0 \
  --port "${GEO_MONITOR_SIDECAR_PORT:-8765}"
