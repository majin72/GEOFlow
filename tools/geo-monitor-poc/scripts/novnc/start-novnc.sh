#!/usr/bin/env bash
# 启动 Xvfb + fluxbox + x11vnc + websockify/noVNC（默认仅监听 127.0.0.1）。
set -euo pipefail

# shellcheck source=common.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/common.sh"

require_linux
require_commands

export DISPLAY="$GEO_MONITOR_DISPLAY"

if ! is_running "$XVFB_PID_FILE"; then
  echo "启动 Xvfb $GEO_MONITOR_DISPLAY ($GEO_MONITOR_XVFB_SCREEN) ..."
  Xvfb "$GEO_MONITOR_DISPLAY" -screen 0 "$GEO_MONITOR_XVFB_SCREEN" -ac +extension GLX +render -noreset &
  echo $! >"$XVFB_PID_FILE"
  sleep 1
else
  echo "Xvfb 已在运行 (pid $(cat "$XVFB_PID_FILE"))"
fi

if ! is_running "$FLUXBOX_PID_FILE"; then
  echo "启动 fluxbox ..."
  fluxbox -display "$GEO_MONITOR_DISPLAY" &
  echo $! >"$FLUXBOX_PID_FILE"
  sleep 1
else
  echo "fluxbox 已在运行 (pid $(cat "$FLUXBOX_PID_FILE"))"
fi

VNC_AUTH_ARGS=(-nopw)
if [[ -f "$GEO_MONITOR_VNC_PASSWORD_FILE" ]]; then
  VNC_AUTH_ARGS=(-rfbauth "$GEO_MONITOR_VNC_PASSWORD_FILE")
  echo "使用 VNC 密码文件: $GEO_MONITOR_VNC_PASSWORD_FILE"
fi

if ! is_running "$X11VNC_PID_FILE"; then
  echo "启动 x11vnc :$GEO_MONITOR_VNC_PORT ..."
  x11vnc -display "$GEO_MONITOR_DISPLAY" -forever -shared -rfbport "$GEO_MONITOR_VNC_PORT" \
    -localhost "${VNC_AUTH_ARGS[@]}" &
  echo $! >"$X11VNC_PID_FILE"
  sleep 1
else
  echo "x11vnc 已在运行 (pid $(cat "$X11VNC_PID_FILE"))"
fi

if ! is_running "$WEBSOCKIFY_PID_FILE"; then
  NOVNC_WEB=""
  for candidate in /usr/share/novnc /usr/share/novnc/vnc /opt/novnc; do
    if [[ -d "$candidate" ]]; then
      NOVNC_WEB="$candidate"
      break
    fi
  done
  if [[ -z "$NOVNC_WEB" ]]; then
    echo "未找到 noVNC 静态文件目录，请安装 novnc 包。" >&2
    exit 1
  fi
  echo "启动 websockify ${GEO_MONITOR_NOVNC_BIND}:${GEO_MONITOR_NOVNC_PORT} -> localhost:${GEO_MONITOR_VNC_PORT} ..."
  websockify --web "$NOVNC_WEB" "${GEO_MONITOR_NOVNC_BIND}:${GEO_MONITOR_NOVNC_PORT}" "localhost:${GEO_MONITOR_VNC_PORT}" &
  echo $! >"$WEBSOCKIFY_PID_FILE"
else
  echo "websockify 已在运行 (pid $(cat "$WEBSOCKIFY_PID_FILE"))"
fi

echo ""
echo "noVNC 已就绪。"
echo "  DISPLAY=$GEO_MONITOR_DISPLAY"
echo "  本地访问: http://${GEO_MONITOR_NOVNC_BIND}:${GEO_MONITOR_NOVNC_PORT}/vnc.html"
echo "  生产建议 SSH 隧道: ssh -L ${GEO_MONITOR_NOVNC_PORT}:127.0.0.1:${GEO_MONITOR_NOVNC_PORT} user@your-server"
echo "  然后浏览器打开: http://127.0.0.1:${GEO_MONITOR_NOVNC_PORT}/vnc.html"
