#!/usr/bin/env bash
# 停止 noVNC 运维栈（不删除 profile 数据）。
set -euo pipefail

# shellcheck source=common.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/common.sh"

stop_pid_file() {
  local name="$1"
  local pid_file="$2"
  if is_running "$pid_file"; then
    local pid
    pid="$(cat "$pid_file")"
    echo "停止 $name (pid $pid) ..."
    kill "$pid" 2>/dev/null || true
    sleep 0.5
    kill -9 "$pid" 2>/dev/null || true
  fi
  rm -f "$pid_file"
}

stop_pid_file "websockify" "$WEBSOCKIFY_PID_FILE"
stop_pid_file "x11vnc" "$X11VNC_PID_FILE"
stop_pid_file "fluxbox" "$FLUXBOX_PID_FILE"
stop_pid_file "Xvfb" "$XVFB_PID_FILE"

echo "noVNC 栈已停止。"
