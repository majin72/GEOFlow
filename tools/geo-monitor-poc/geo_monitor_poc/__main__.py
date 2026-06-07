from __future__ import annotations

import sys

from geo_monitor_poc.cli import ask, captcha, discover, login, probe, serve


def main() -> int:
    """
    顶层 CLI 入口。

    @return 进程退出码
    """
    argv = sys.argv[1:]
    if not argv or argv[0] in {"-h", "--help"}:
        print("用法: python -m geo_monitor_poc <login|captcha|discover|probe|ask|serve> [options]")
        print("示例: ./run.sh login --platform doubao --accounts accounts.json")
        return 0 if argv and argv[0] in {"-h", "--help"} else 1

    command = argv[0]
    rest = argv[1:]

    if command == "login":
        return login.run_login(login.build_parser().parse_args(rest))
    if command == "captcha":
        return captcha.run_captcha(captcha.build_parser().parse_args(rest))
    if command == "discover":
        return discover.run_discover(discover.build_parser().parse_args(rest))
    if command == "probe":
        return probe.run_probe_batch(probe.build_parser().parse_args(rest))
    if command == "ask":
        return ask.run_ask(ask.build_parser().parse_args(rest))
    if command == "serve":
        return serve.run_serve(serve.build_parser().parse_args(rest))

    print(f"未知命令: {command}", file=sys.stderr)
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
