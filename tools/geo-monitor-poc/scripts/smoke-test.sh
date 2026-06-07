#!/usr/bin/env bash
# Stage 1 平台可采集性 smoke test（请在本地终端执行，非 Cursor 沙箱）
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

STAMP="$(date +%Y%m%d)"
OUTPUT="./evidence/runs/smoke-${STAMP}"
LIMIT="${1:-5}"
DELAY="${2:-15}"

echo "============================================================"
echo "GEO Monitor POC — Stage 1 Smoke Test"
echo "输出目录: ${OUTPUT}"
echo "每平台题数: ${LIMIT}  题间间隔: ${DELAY}s"
echo "账号: accounts.json（doubao/deepseek/yuanbao 各 01）"
echo "============================================================"
echo ""
echo "验收标准（每平台）："
echo "  - login=logged_in"
echo "  - status=success（有引用）或 partial（有回答无引用）"
echo "  - citations>0 为理想；partial 需记录平台名"
echo ""

./run.sh probe \
  --platform all \
  --accounts accounts.json \
  --prompts prompts.sample.json \
  --limit "${LIMIT}" \
  --delay-seconds "${DELAY}" \
  --output "${OUTPUT}"

echo ""
echo "============================================================"
echo "报告: ${OUTPUT}/latest-report.md"
echo "JSON: ${OUTPUT}/latest-results.json"
echo ""
echo "快速汇总："
OUTPUT="${OUTPUT}" "${ROOT_DIR}/.venv/bin/python" - <<'PY'
import json
import os
from pathlib import Path

output = Path(os.environ["OUTPUT"])
path = output / "latest-results.json"
if not path.exists():
    print("未找到 latest-results.json")
    raise SystemExit(0)

rows = json.loads(path.read_text(encoding="utf-8"))
by_platform: dict[str, list] = {}
for row in rows:
    by_platform.setdefault(row["platform"], []).append(row)

print(f"{'平台':<10} {'成功':>4} {'部分':>4} {'失败':>4} {'验证码':>6} {'需登录':>6} {'引用合计':>8}")
print("-" * 52)
for platform in ("doubao", "deepseek", "yuanbao"):
    items = by_platform.get(platform, [])
    if not items:
        print(f"{platform:<10} {'—':>4} {'—':>4} {'—':>4} {'—':>6} {'—':>6} {'—':>8}")
        continue
    success = sum(1 for i in items if i["status"] == "success")
    partial = sum(1 for i in items if i["status"] == "partial")
    failed = sum(1 for i in items if i["status"] in ("failed", "selector_miss"))
    captcha = sum(1 for i in items if i["status"] == "captcha_required")
    needs_login = sum(1 for i in items if i["status"] == "needs_login")
    cites = sum(len(i.get("citations") or []) for i in items)
    print(f"{platform:<10} {success:>4} {partial:>4} {failed:>4} {captcha:>6} {needs_login:>6} {cites:>8}")
PY
