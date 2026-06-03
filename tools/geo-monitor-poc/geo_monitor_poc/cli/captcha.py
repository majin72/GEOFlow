from __future__ import annotations

import argparse
import sys
from pathlib import Path

from geo_monitor_poc.adapters import create_adapter
from geo_monitor_poc.browser import open_platform_session
from geo_monitor_poc.cli.ask import resolve_account
from geo_monitor_poc.models import PlatformId

# 人工过验证码可能较慢，单独给更长超时（10 分钟）
CAPTCHA_BROWSER_TIMEOUT_MS = 600_000


def build_parser() -> argparse.ArgumentParser:
    """
    构造 captcha 命令参数解析器。

    @return ArgumentParser
    """
    parser = argparse.ArgumentParser(
        description="打开浏览器人工过验证码；只有你在终端按 Enter 后才会关闭",
    )
    parser.add_argument(
        "--platform",
        default="doubao",
        choices=[item.value for item in PlatformId],
        help="目标平台，默认 doubao",
    )
    parser.add_argument(
        "--accounts",
        default="accounts.json",
        help="账号配置；不存在则自动使用 guest profile",
    )
    parser.add_argument(
        "--account-id",
        default="",
        help="指定账号/profile ID，例如 doubao_guest",
    )
    return parser


def run_captcha(args: argparse.Namespace) -> int:
    """
    打开可见浏览器，等待人工完成验证码后再关闭。

    @param args CLI 参数
    @return 进程退出码
    """
    platform = PlatformId(args.platform)
    account = resolve_account(args)
    adapter = create_adapter(account)
    chat_url = adapter.selectors.chat_url

    print("=" * 60)
    print(f"平台: {platform.value}")
    print(f"账号/profile: {account.id}")
    print(f"Profile 目录: {Path(account.profile_dir).resolve()}")
    print(f"URL: {chat_url}")
    print("")
    print("浏览器将保持打开，直到你在终端按 Enter。")
    print("请在浏览器里完成验证码（如有），也可顺便登录。")
    print("=" * 60)

    def hold_for_captcha(page) -> None:
        page.wait_for_timeout(2_000)
        if adapter.has_captcha(page):
            print("\n[检测] 当前页面存在验证码遮罩，请先在浏览器里完成验证。")
        else:
            print("\n[检测] 暂未发现验证码遮罩；若页面稍后弹出验证码，可直接在浏览器里处理。")

        print("\n>>> 处理完成后，回到终端按 Enter，才会关闭浏览器并保存 profile。\n")
        input()

    with open_platform_session(
        account,
        headless=False,
        timeout_ms=CAPTCHA_BROWSER_TIMEOUT_MS,
    ) as session:
        session.fetch(chat_url, page_action=hold_for_captcha, load_dom=True)

    print("Profile 已保存。现在可以执行 ask / probe。")
    return 0


def main() -> int:
    """
    CLI 主入口。

    @return 进程退出码
    """
    parser = build_parser()
    args = parser.parse_args()
    return run_captcha(args)


if __name__ == "__main__":
    raise SystemExit(main())
