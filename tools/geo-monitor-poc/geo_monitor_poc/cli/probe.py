from __future__ import annotations

import argparse
import json
import sys
import time
from pathlib import Path

from geo_monitor_poc.citations import save_json
from geo_monitor_poc.models import PlatformId, ProbeResult, ProbeStatus
from geo_monitor_poc.probe_runner import run_probe
from geo_monitor_poc.report import save_report
from geo_monitor_poc.utils import build_run_dir, find_account, load_accounts, load_prompts


def build_parser() -> argparse.ArgumentParser:
    """
    构造 probe 命令参数解析器。

    @return ArgumentParser
    """
    parser = argparse.ArgumentParser(description="执行 GEO 引用度 POC 探测")
    parser.add_argument(
        "--platform",
        default="all",
        help="目标平台：doubao / deepseek / yuanbao / all",
    )
    parser.add_argument(
        "--accounts",
        default="accounts.sample.json",
        help="账号配置文件路径",
    )
    parser.add_argument(
        "--prompts",
        default="prompts.sample.json",
        help="问题集文件路径",
    )
    parser.add_argument(
        "--output",
        default="./evidence/runs",
        help="证据与报告输出目录",
    )
    parser.add_argument(
        "--production",
        action="store_true",
        help="生产模式：无头 + 非交互；遇验证码立即失败并告警（推荐定时任务使用）",
    )
    parser.add_argument(
        "--headless",
        action="store_true",
        help="无头模式运行",
    )
    parser.add_argument(
        "--no-interactive",
        action="store_true",
        help="不在终端暂停等待验证码（无头/生产必开）",
    )
    parser.add_argument(
        "--delay-seconds",
        type=int,
        default=15,
        help="每题之间的等待秒数，降低风控概率",
    )
    parser.add_argument(
        "--limit",
        type=int,
        default=0,
        help="每个平台最多执行多少题，0 表示全部",
    )
    return parser


def resolve_platforms(raw: str) -> list[PlatformId]:
    """
    解析平台参数。

    @param raw CLI 平台参数
    @return 平台列表
    """
    if raw.strip().lower() == "all":
        return list(PlatformId)

    return [PlatformId(raw.strip().lower())]


def run_probe_batch(args: argparse.Namespace) -> int:
    """
    批量执行探测并输出报告。

    @param args CLI 参数
    @return 进程退出码
    """
    accounts_path = Path(args.accounts)
    prompts_path = Path(args.prompts)
    if not accounts_path.exists():
        print(f"账号配置不存在: {accounts_path}", file=sys.stderr)
        return 1
    if not prompts_path.exists():
        print(f"问题集不存在: {prompts_path}", file=sys.stderr)
        return 1

    accounts = load_accounts(accounts_path)
    prompts = load_prompts(prompts_path)
    if args.limit > 0:
        prompts = prompts[: args.limit]

    platforms = resolve_platforms(args.platform)
    all_results: list[ProbeResult] = []
    headless = args.headless or args.production
    interactive = not headless and not args.no_interactive and not args.production

    if args.production:
        print("生产模式: headless=是, interactive=否, 验证码将触发告警后退出")

    for platform in platforms:
        account = find_account(accounts, platform)
        if account is None:
            print(f"跳过 {platform.value}: 未配置启用账号", file=sys.stderr)
            continue

        run_dir = build_run_dir(Path(args.output), platform, account.id)
        print(f"\n>>> 平台 {platform.value} / 账号 {account.id}")
        print(f">>> 证据目录: {run_dir.resolve()}")

        for index, prompt in enumerate(prompts):
            print(f"[{index + 1}/{len(prompts)}] {prompt['id']}: {prompt['text'][:60]}...")
            result = run_probe(
                account,
                prompt_id=prompt["id"],
                prompt_text=prompt["text"],
                evidence_dir=run_dir,
                headless=headless,
                interactive=interactive,
            )
            all_results.append(result)
            save_json(run_dir / f"{prompt['id']}.json", result.to_dict())
            print(
                f"    status={result.status.value} login={result.login_status.value} "
                f"citations={len(result.citations)} duration={result.duration_ms}ms"
            )
            if result.status == ProbeStatus.CAPTCHA:
                print("    >>> 需人工: ./run.sh captcha --platform", platform.value, "--account-id", account.id)

            if index < len(prompts) - 1 and args.delay_seconds > 0:
                time.sleep(args.delay_seconds)

    if not all_results:
        print("没有产生任何探测结果", file=sys.stderr)
        return 1

    report_path = Path(args.output) / "latest-report.md"
    json_path = Path(args.output) / "latest-results.json"
    save_report(all_results, report_path)
    json_path.write_text(
        json.dumps([item.to_dict() for item in all_results], ensure_ascii=False, indent=2),
        encoding="utf-8",
    )

    print("\n完成。")
    print(f"报告: {report_path.resolve()}")
    print(f"JSON: {json_path.resolve()}")
    return 0


def main() -> int:
    """
    CLI 主入口。

    @return 进程退出码
    """
    parser = build_parser()
    args = parser.parse_args()
    return run_probe_batch(args)


if __name__ == "__main__":
    raise SystemExit(main())
