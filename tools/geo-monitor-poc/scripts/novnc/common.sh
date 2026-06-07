#!/usr/bin/env bash
# noVNC 运维通道共享配置（仅 Linux 服务器使用）。
set -euo pipefail

NOVNC_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
POC_ROOT="$(cd "$NOVNC_SCRIPT_DIR/../.." && pwd)"

export POC_ROOT
export GEO_MONITOR_DISPLAY="${GEO_MONITOR_DISPLAY:-:99}"
export GEO_MONITOR_XVFB_SCREEN="${GEO_MONITOR_XVFB_SCREEN:-1920x1080x24}"
export GEO_MONITOR_VNC_PORT="${GEO_MONITOR_VNC_PORT:-5900}"
export GEO_MONITOR_NOVNC_PORT="${GEO_MONITOR_NOVNC_PORT:-6080}"
export GEO_MONITOR_NOVNC_BIND="${GEO_MONITOR_NOVNC_BIND:-127.0.0.1}"
export GEO_MONITOR_VNC_PASSWORD_FILE="${GEO_MONITOR_VNC_PASSWORD_FILE:-$POC_ROOT/.novnc-state/vnc.passwd}"

# Docker 端口映射转发到容器网桥 IP，若 websockify 只绑 127.0.0.1 会导致宿主机 curl/SSH 隧道连接被重置。
if [[ -f /.dockerenv && "$GEO_MONITOR_NOVNC_BIND" == "127.0.0.1" ]]; then
  export GEO_MONITOR_NOVNC_BIND="0.0.0.0"
fi

STATE_DIR="$POC_ROOT/.novnc-state"
XVFB_PID_FILE="$STATE_DIR/xvfb.pid"
FLUXBOX_PID_FILE="$STATE_DIR/fluxbox.pid"
X11VNC_PID_FILE="$STATE_DIR/x11vnc.pid"
WEBSOCKIFY_PID_FILE="$STATE_DIR/websockify.pid"

mkdir -p "$STATE_DIR"

# 检查 PID 文件对应进程是否仍在运行。
is_running() {
  local pid_file="$1"
  if [[ ! -f "$pid_file" ]]; then
    return 1
  fi
  local pid
  pid="$(cat "$pid_file")"
  if [[ -z "$pid" ]]; then
    return 1
  fi
  kill -0 "$pid" 2>/dev/null
}

# 容器重启后清理失效 PID 与 X display 锁，避免 Xvfb「already active」循环。
cleanup_stale_novnc_state() {
  local display_num="${GEO_MONITOR_DISPLAY#:}"
  local lock="/tmp/.X${display_num}-lock"
  local socket="/tmp/.X11-unix/X${display_num}"
  local pid_file

  for pid_file in "$XVFB_PID_FILE" "$FLUXBOX_PID_FILE" "$X11VNC_PID_FILE" "$WEBSOCKIFY_PID_FILE"; do
    if [[ -f "$pid_file" ]] && ! is_running "$pid_file"; then
      rm -f "$pid_file"
    fi
  done

  if [[ -f "$lock" ]] && ! is_running "$XVFB_PID_FILE"; then
    echo "清理失效 X display 锁: $lock"
    rm -f "$lock" "$socket"
  fi
}

require_linux() {
  if [[ "$(uname -s)" != "Linux" ]]; then
    echo "noVNC 运维脚本仅支持 Linux 服务器（当前: $(uname -s)）。" >&2
    echo "本地 macOS/Windows 请使用 ./run.sh captcha 或 login。" >&2
    exit 1
  fi
}

require_commands() {
  local missing=()
  for cmd in Xvfb fluxbox x11vnc websockify; do
    if ! command -v "$cmd" >/dev/null 2>&1; then
      missing+=("$cmd")
    fi
  done
  if [[ ${#missing[@]} -gt 0 ]]; then
    echo "缺少依赖: ${missing[*]}" >&2
    echo "Debian/Ubuntu 示例: apt install -y xvfb fluxbox x11vnc novnc websockify" >&2
    exit 1
  fi
}
