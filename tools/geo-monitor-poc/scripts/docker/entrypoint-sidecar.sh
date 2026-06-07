#!/usr/bin/env bash
# Sidecar 容器入口：先启动 noVNC 栈，再 exec sidecar API（避免 shell && 导致重启时重复拉起 X）。
set -eu

cd /app

EVIDENCE_ROOT="${GEO_MONITOR_EVIDENCE_ROOT:-/var/www/html/storage/app/geo-monitor/evidence}"

# 与 Laravel app 容器（www-data UID 33）共享 storage bind mount
mkdir -p "${EVIDENCE_ROOT}" /app/profiles
if [ -d "${EVIDENCE_ROOT}" ]; then
  chmod -R u+rwX,g+rwX,o+rX "${EVIDENCE_ROOT}" 2>/dev/null || true
fi
if [ -d /app/profiles ]; then
  chmod -R u+rwX,g+rwX,o+rX /app/profiles 2>/dev/null || true
fi
if [ -n "${GEO_MONITOR_APP_UID:-}" ] && [ -n "${GEO_MONITOR_APP_GID:-}" ]; then
  chown -R "${GEO_MONITOR_APP_UID}:${GEO_MONITOR_APP_GID}" "${EVIDENCE_ROOT}" /app/profiles 2>/dev/null || true
fi

bash ./scripts/novnc/start-novnc.sh

exec python -m geo_monitor_poc serve \
  --host 0.0.0.0 \
  --port "${GEO_MONITOR_SIDECAR_PORT:-8765}"
