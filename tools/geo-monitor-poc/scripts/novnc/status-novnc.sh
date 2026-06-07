#!/usr/bin/env bash
# 查看 noVNC 运维栈进程状态。
set -euo pipefail

# shellcheck source=common.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/common.sh"

print_status() {
  local name="$1"
  local pid_file="$2"
  if is_running "$pid_file"; then
    echo "  $name: running (pid $(cat "$pid_file"))"
  else
    echo "  $name: stopped"
  fi
}

echo "GEO Monitor noVNC 状态"
echo "  POC_ROOT=$POC_ROOT"
echo "  DISPLAY=$GEO_MONITOR_DISPLAY"
echo "  noVNC bind=${GEO_MONITOR_NOVNC_BIND}:${GEO_MONITOR_NOVNC_PORT}"
print_status "Xvfb" "$XVFB_PID_FILE"
print_status "fluxbox" "$FLUXBOX_PID_FILE"
print_status "x11vnc" "$X11VNC_PID_FILE"
print_status "websockify" "$WEBSOCKIFY_PID_FILE"

if is_running "$WEBSOCKIFY_PID_FILE"; then
  echo ""
  echo "访问: http://${GEO_MONITOR_NOVNC_BIND}:${GEO_MONITOR_NOVNC_PORT}/vnc.html"
fi
