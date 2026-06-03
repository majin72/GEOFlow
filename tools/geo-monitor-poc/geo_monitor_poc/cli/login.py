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
    构造 login 命令参数解析器。

    @return ArgumentParser
    """
    parser = argparse.ArgumentParser(description="打开浏览器供人工登录 AI 平台")
    parser.add_argument(
        "--platform",
        required=True,
        choices=[item.value for item in PlatformId],
        help="目标平台：doubao / deepseek / yuanbao",
    )
    parser.add_argument(
        "--accounts",
        default="accounts.sample.json",
        help="账号配置文件路径",
    )
    parser.add_argument(
        "--account-id",
        default="",
        help="指定账号 ID；默认取该平台第一个启用账号",
    )
    return parser


def run_login(args: argparse.Namespace) -> int:
    """
    执行人工登录流程。

    @param args CLI 参数
    @return 进程退出码
    """
    accounts_path = Path(args.accounts)
    if not accounts_path.exists():
        print(f"账号配置文件不存在: {accounts_path}", file=sys.stderr)
        return 1

    accounts = load_accounts(accounts_path)
    platform = PlatformId(args.platform)

    account = None
    if args.account_id:
        account = next((item for item in accounts if item.id == args.account_id), None)
    else:
        account = find_account(accounts, platform)

    if account is None:
        print(f"未找到可用账号: platform={platform.value}", file=sys.stderr)
        return 1

    adapter = create_adapter(account)
    chat_url = adapter.selectors.chat_url

    print("=" * 60)
    print(f"平台: {platform.value}")
    print(f"账号: {account.id}")
    print(f"Profile: {Path(account.profile_dir).resolve()}")
    print(f"URL: {chat_url}")
    print("请在弹出的浏览器中完成登录，然后回到终端按 Enter 关闭。")
    print("=" * 60)

    def hold_page(page) -> None:
        print("浏览器已打开。请在页面中完成登录，然后回到终端按 Enter。")
        input()

    with open_platform_session(
        account,
        headless=False,
        timeout_ms=DEFAULT_PROBE_TIMEOUT_MS,
    ) as session:
        session.fetch(chat_url, page_action=hold_page, load_dom=True)

    print("Profile 已保存，可继续执行 probe。")
    return 0


def main() -> int:
    """
    CLI 主入口。

    @return 进程退出码
    """
    parser = build_parser()
    args = parser.parse_args()
    return run_login(args)


if __name__ == "__main__":
    raise SystemExit(main())
