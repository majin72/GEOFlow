#!/usr/bin/env bash
# 在 noVNC 可见浏览器中维护指定平台账号 profile（验证码/登录）。
set -euo pipefail

# shellcheck source=common.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/common.sh"

usage() {
  cat <<'EOF'
用法: maintain-profile.sh --platform <doubao|deepseek|yuanbao> --account-id <external_id> [--mode captcha|login]

步骤:
  1. 启动 noVNC 栈（若未运行）
  2. SSH 隧道访问 http://127.0.0.1:6080/vnc.html
  3. 在远程桌面完成验证码或登录
  4. 回到本终端按 Enter 保存 profile

示例:
  ./scripts/novnc/maintain-profile.sh --platform doubao --account-id doubao_guest
  ./scripts/novnc/maintain-profile.sh --platform deepseek --account-id deepseek_account_01 --mode login
EOF
}

PLATFORM=""
ACCOUNT_ID=""
MODE="captcha"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --platform)
      PLATFORM="$2"
      shift 2
      ;;
    --account-id)
      ACCOUNT_ID="$2"
      shift 2
      ;;
    --mode)
      MODE="$2"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "未知参数: $1" >&2
      usage
      exit 1
      ;;
  esac
done

if [[ -z "$PLATFORM" || -z "$ACCOUNT_ID" ]]; then
  usage
  exit 1
fi

if [[ "$MODE" != "captcha" && "$MODE" != "login" ]]; then
  echo "--mode 仅支持 captcha 或 login" >&2
  exit 1
fi

require_linux

"$NOVNC_SCRIPT_DIR/start-novnc.sh"

export DISPLAY="$GEO_MONITOR_DISPLAY"
cd "$POC_ROOT"

echo ""
echo "请在另一终端建立 SSH 隧道后打开 noVNC："
echo "  ssh -L ${GEO_MONITOR_NOVNC_PORT}:127.0.0.1:${GEO_MONITOR_NOVNC_PORT} user@your-server"
echo "  浏览器: http://127.0.0.1:${GEO_MONITOR_NOVNC_PORT}/vnc.html"
echo ""
echo "维护命令: platform=$PLATFORM account=$ACCOUNT_ID mode=$MODE"
echo ""

exec ./run.sh "$MODE" --platform "$PLATFORM" --account-id "$ACCOUNT_ID"
