#!/usr/bin/env bash
# Sidecar 容器入口：先启动 noVNC 栈，再 exec sidecar API（避免 shell && 导致重启时重复拉起 X）。
set -eu

cd /app

EVIDENCE_ROOT="${GEO_MONITOR_EVIDENCE_ROOT:-/var/www/html/storage/app/geo-monitor/evidence}"
GEO_MONITOR_APP_UID="${GEO_MONITOR_APP_UID:-33}"
GEO_MONITOR_APP_GID="${GEO_MONITOR_APP_GID:-33}"
STORAGE_GEO="${EVIDENCE_ROOT%/evidence}"

mkdir -p "${EVIDENCE_ROOT}" /app/profiles

# 与 Laravel app 共用 ./storage 挂载：创建目录链并统一组权限（www-data GID 33）
for dir in "${STORAGE_GEO}" "${EVIDENCE_ROOT}" /app/profiles; do
  mkdir -p "${dir}"
  chmod 0775 "${dir}" 2>/dev/null || true
  chown "${GEO_MONITOR_APP_UID}:${GEO_MONITOR_APP_GID}" "${dir}" 2>/dev/null || true
done

bash ./scripts/novnc/start-novnc.sh

exec python -m geo_monitor_poc serve \
  --host 0.0.0.0 \
  --port "${GEO_MONITOR_SIDECAR_PORT:-8765}"
