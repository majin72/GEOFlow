#!/usr/bin/env bash
# 有头/无头通用：维护后无头登录态健康检查。
set -euo pipefail

POC_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$POC_ROOT"

PLATFORM=""
ACCOUNT_ID=""

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
    -h|--help)
      echo "用法: health-check.sh --platform <code> --account-id <external_id>"
      exit 0
      ;;
    *)
      echo "未知参数: $1" >&2
      exit 1
      ;;
  esac
done

if [[ -z "$PLATFORM" || -z "$ACCOUNT_ID" ]]; then
  echo "缺少 --platform 或 --account-id" >&2
  exit 1
fi

exec ./run.sh ask --production --platform "$PLATFORM" \
  --account-id "$ACCOUNT_ID" --require-login \
  -q "请用一句话回答：今天适合做一次登录态健康检查吗？"
