from __future__ import annotations

import argparse

from geo_monitor_poc.sidecar.config import SidecarSettings, load_settings
from geo_monitor_poc.sidecar.server import run_server


def build_parser() -> argparse.ArgumentParser:
    """
    构造 serve 命令参数解析器。

    @return ArgumentParser
    """
    parser = argparse.ArgumentParser(description="启动 GEO Monitor HTTP sidecar")
    parser.add_argument("--host", default="", help="监听地址，默认 GEO_MONITOR_SIDECAR_HOST")
    parser.add_argument("--port", type=int, default=0, help="端口，默认 GEO_MONITOR_SIDECAR_PORT")
    return parser


def run_serve(args: argparse.Namespace) -> int:
    """
    启动 sidecar 服务。

    @param args CLI 参数
    @return 进程退出码
    """
    settings = load_settings()
    host = args.host or settings.host
    port = args.port or settings.port

    overridden = SidecarSettings(
        host=host,
        port=port,
        token=settings.token,
        accounts_file=settings.accounts_file,
        evidence_root=settings.evidence_root,
        probe_timeout_ms=settings.probe_timeout_ms,
        probe_rate_limit_per_minute=settings.probe_rate_limit_per_minute,
        session_rate_limit_per_minute=settings.session_rate_limit_per_minute,
        default_rate_limit_per_minute=settings.default_rate_limit_per_minute,
        allow_localhost_without_token=settings.allow_localhost_without_token,
    )
    run_server(overridden)
    return 0


def main() -> int:
    """
    CLI 主入口。

    @return 进程退出码
    """
    return run_serve(build_parser().parse_args())
