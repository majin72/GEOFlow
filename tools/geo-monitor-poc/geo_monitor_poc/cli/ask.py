from __future__ import annotations

import argparse
import sys
from pathlib import Path

from geo_monitor_poc.config import PLATFORM_SELECTORS
from geo_monitor_poc.models import AccountConfig, PlatformId
from geo_monitor_poc.probe_runner import run_probe
from geo_monitor_poc.utils import build_run_dir, find_account, load_accounts, now_ms


def build_parser() -> argparse.ArgumentParser:
    """
    构造 ask 命令参数解析器。

    @return ArgumentParser
    """
    parser = argparse.ArgumentParser(
        description="单题快速测试：打开页面、输入问题、抓取回答（豆包可不登录）",
    )
    parser.add_argument(
        "--platform",
        default="doubao",
        choices=[item.value for item in PlatformId],
        help="目标平台，默认 doubao",
    )
    parser.add_argument(
        "--question",
        "-q",
        required=True,
        help="要发送的问题",
    )
    parser.add_argument(
        "--accounts",
        default="accounts.json",
        help="账号配置；不存在则自动使用 guest profile",
    )
    parser.add_argument(
        "--account-id",
        default="",
        help="指定账号 ID",
    )
    parser.add_argument(
        "--output",
        default="./evidence/ask",
        help="证据输出目录",
    )
    parser.add_argument(
        "--headless",
        action="store_true",
        help="无头模式；出现验证码时无法人工处理",
    )
    parser.add_argument(
        "--no-interactive",
        action="store_true",
        help="不在终端暂停等待验证码/人工操作（默认可见浏览器时会暂停）",
    )
    parser.add_argument(
        "--require-login",
        action="store_true",
        help="强制检查登录态；豆包访客模式不要加此参数",
    )
    return parser


def resolve_account(args: argparse.Namespace) -> AccountConfig:
    """
    解析账号配置；无配置文件时使用 guest profile。

    @param args CLI 参数
    @return 账号配置
    """
    platform = PlatformId(args.platform)
    accounts_path = Path(args.accounts)

    if accounts_path.exists():
        accounts = load_accounts(accounts_path)
        if args.account_id:
            account = next((item for item in accounts if item.id == args.account_id), None)
            if account is not None:
                return account

            # 显式指定了 profile 名（如 doubao_guest），但 accounts.json 未配置时仍使用该目录
            return AccountConfig(
                id=args.account_id,
                platform=platform,
                label=args.account_id,
                profile_dir=f"./profiles/{args.account_id}",
            )

        account = find_account(accounts, platform)
        if account is not None:
            return account

    return AccountConfig(
        id=f"{platform.value}_guest",
        platform=platform,
        label=f"{platform.value} guest",
        profile_dir=f"./profiles/{platform.value}_guest",
    )


def run_ask(args: argparse.Namespace) -> int:
    """
    执行单题 ask 测试。

    @param args CLI 参数
    @return 进程退出码
    """
    platform = PlatformId(args.platform)
    selectors = PLATFORM_SELECTORS[platform]
    account = resolve_account(args)
    evidence_dir = build_run_dir(Path(args.output), platform, account.id)

    skip_login_check = not args.require_login and selectors.guest_mode_allowed
    interactive = not args.headless and not args.no_interactive

    print("=" * 60)
    print(f"平台: {platform.value}")
    print(f"账号/profile: {account.id} -> {Path(account.profile_dir).resolve()}")
    print(f"访客模式: {'是' if skip_login_check else '否'}")
    print(f"无头模式: {'是' if args.headless else '否（可见浏览器，便于观察输入过程）'}")
    print(f"交互暂停: {'是（验证码时会等待你在终端按 Enter）' if interactive else '否'}")
    print(f"问题: {args.question}")
    print("=" * 60)

    result = run_probe(
        account,
        prompt_id=f"ask_{now_ms()}",
        prompt_text=args.question,
        evidence_dir=evidence_dir,
        headless=args.headless,
        skip_login_check=skip_login_check,
        interactive=interactive,
    )

    print("")
    print(f"状态: {result.status.value}")
    print(f"登录检测: {result.login_status.value}")
    print(f"引用数: {len(result.citations)}")
    print(f"耗时: {result.duration_ms}ms")
    if result.error_message:
        print(f"错误: {result.error_message}")

    print("")
    print("--- 回答 ---")
    print(result.answer_text or "(empty)")
    print("---")

    if result.citations:
        print("")
        print("--- 引用 ---")
        for item in result.citations:
            title = item.title or item.url
            print(f"- {title} -> {item.url}")

    print("")
    print(f"截图: {result.evidence.screenshot_path}")
    print(f"HTML: {result.evidence.html_path}")
    print(f"目录: {evidence_dir.resolve()}")

    if result.status.value in {"failed", "selector_miss", "needs_login"}:
        print("")
        print("若输入框未命中，可先运行:")
        print(f"  ./run.sh discover --platform {platform.value}")

    return 0 if result.answer_text.strip() else 1


def main() -> int:
    """
    CLI 主入口。

    @return 进程退出码
    """
    parser = build_parser()
    args = parser.parse_args()
    return run_ask(args)


if __name__ == "__main__":
    raise SystemExit(main())
