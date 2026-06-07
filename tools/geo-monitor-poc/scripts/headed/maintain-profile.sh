#!/usr/bin/env bash
# 有头桌面维护：macOS / Windows(Git Bash/WSL) / 带 DISPLAY 的 Linux。
# 直接弹出可见 Chromium，无需 Xvfb/noVNC。
set -euo pipefail

POC_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

usage() {
  cat <<'EOF'
用法: maintain-profile.sh --platform <doubao|deepseek|yuanbao> --account-id <external_id> [--mode captcha|login]

适用环境:
  - macOS 本机
  - Windows WSL / Git Bash（需已安装 Python venv 与 Chromium）
  - Linux 桌面（需 DISPLAY 已设置）

步骤:
  1. 同步 Laravel 后台「sidecar 账号」到 accounts.json
  2. 执行本脚本，在弹出的浏览器中完成验证码或登录
  3. 终端按 Enter 保存 profile
  4. 执行 health-check.sh 或后台「完成维护」

示例:
  ./scripts/headed/maintain-profile.sh --platform doubao --account-id doubao_guest
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

OS="$(uname -s)"
if [[ "$OS" == "Linux" && -z "${DISPLAY:-}" ]]; then
  echo "当前 Linux 无 DISPLAY。请使用有桌面会话，或改用 headless_linux + scripts/novnc/maintain-profile.sh" >&2
  exit 1
fi

cd "$POC_ROOT"

echo "有头维护: platform=$PLATFORM account=$ACCOUNT_ID mode=$MODE (OS=$OS)"
echo "浏览器将直接弹出，请勿加 --headless。"
echo ""

exec ./run.sh "$MODE" --platform "$PLATFORM" --account-id "$ACCOUNT_ID"
