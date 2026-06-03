from __future__ import annotations

import argparse
import sys
from pathlib import Path

from geo_monitor_poc.adapters import create_adapter
from geo_monitor_poc.browser import open_platform_session
from geo_monitor_poc.config import DEFAULT_PROBE_TIMEOUT_MS
from geo_monitor_poc.models import PlatformId
from geo_monitor_poc.utils import find_account, load_accounts


def build_parser() -> argparse.ArgumentParser:
    """
    构造 discover 命令参数解析器。

    @return ArgumentParser
    """
    parser = argparse.ArgumentParser(description="保存平台页面 DOM 快照，用于 selector 调试")
    parser.add_argument(
        "--platform",
        required=True,
        choices=[item.value for item in PlatformId],
        help="目标平台",
    )
    parser.add_argument(
        "--accounts",
        default="accounts.sample.json",
        help="账号配置文件路径",
    )
    parser.add_argument(
        "--output",
        default="./evidence/discover",
        help="输出目录",
    )
    parser.add_argument(
        "--headless",
        action="store_true",
        help="无头模式运行",
    )
    return parser


def run_discover(args: argparse.Namespace) -> int:
    """
    保存页面 HTML 与截图，便于更新 selector。

    @param args CLI 参数
    @return 进程退出码
    """
    accounts = load_accounts(Path(args.accounts))
    platform = PlatformId(args.platform)
    account = find_account(accounts, platform)
    if account is None:
        print(f"未找到平台账号: {platform.value}", file=sys.stderr)
        return 1

    adapter = create_adapter(account)
    output_dir = Path(args.output) / platform.value
    output_dir.mkdir(parents=True, exist_ok=True)

    def capture(page) -> None:
        html_path = output_dir / "page.html"
        screenshot_path = output_dir / "page.png"
        html_path.write_text(page.content(), encoding="utf-8")
        page.screenshot(path=str(screenshot_path), full_page=True)
        login_status = adapter.detect_login_status(page)
        (output_dir / "login_status.txt").write_text(login_status.value, encoding="utf-8")

    with open_platform_session(
        account,
        headless=args.headless,
        timeout_ms=DEFAULT_PROBE_TIMEOUT_MS,
    ) as session:
        session.fetch(adapter.selectors.chat_url, page_action=capture, load_dom=True)

    print(f"已保存 DOM 快照到: {output_dir.resolve()}")
    print("请检查 page.html / page.png，并更新 geo_monitor_poc/config.py 中的 selectors。")
    return 0


def main() -> int:
    """
    CLI 主入口。

    @return 进程退出码
    """
    parser = build_parser()
    args = parser.parse_args()
    return run_discover(args)


if __name__ == "__main__":
    raise SystemExit(main())
