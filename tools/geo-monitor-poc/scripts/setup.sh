#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

PYTHON_BIN="${PYTHON_BIN:-python3}"
VENV_DIR="${VENV_DIR:-.venv}"

if [[ ! -d "$VENV_DIR" ]]; then
  "$PYTHON_BIN" -m venv "$VENV_DIR"
fi

# shellcheck disable=SC1091
source "$VENV_DIR/bin/activate"

pip install -U pip
pip install -r requirements.txt

if command -v scrapling >/dev/null 2>&1; then
  scrapling install --force
else
  python -m scrapling install --force
fi

mkdir -p profiles evidence/runs evidence/discover

if [[ ! -f accounts.json ]]; then
  cp accounts.sample.json accounts.json
  echo "已生成 accounts.json，请按需修改 profile/proxy。"
fi

echo ""
echo "环境就绪。请使用以下任一方式运行（不要直接用 conda/base 的 python）："
echo ""
echo "  方式 1（推荐）:"
echo "    ./run.sh captcha --platform doubao --account-id doubao_guest"
echo "    ./run.sh ask --platform doubao -q '你好' --account-id doubao_guest"
echo ""
echo "  方式 2:"
echo "    source .venv/bin/activate"
echo "    python -m geo_monitor_poc login --platform deepseek --accounts accounts.json"
echo ""
echo "  方式 3:"
echo "    .venv/bin/python -m geo_monitor_poc login --platform deepseek --accounts accounts.json"
