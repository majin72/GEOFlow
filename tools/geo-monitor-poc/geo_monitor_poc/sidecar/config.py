from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path


@dataclass(frozen=True, slots=True)
class SidecarSettings:
    """Sidecar 运行时配置（环境变量）。"""

    host: str
    port: int
    token: str
    accounts_file: Path
    evidence_root: Path
    probe_timeout_ms: int
    probe_rate_limit_per_minute: int
    session_rate_limit_per_minute: int
    default_rate_limit_per_minute: int
    allow_localhost_without_token: bool


def load_settings() -> SidecarSettings:
    """
    从环境变量加载 sidecar 配置。

    @return SidecarSettings
    """
    token = os.environ.get("GEO_MONITOR_SIDECAR_TOKEN", "").strip()
    return SidecarSettings(
        host=os.environ.get("GEO_MONITOR_SIDECAR_HOST", "127.0.0.1"),
        port=int(os.environ.get("GEO_MONITOR_SIDECAR_PORT", "8765")),
        token=token,
        accounts_file=Path(
            os.environ.get("GEO_MONITOR_ACCOUNTS_FILE", "./accounts.json"),
        ).resolve(),
        evidence_root=Path(
            os.environ.get("GEO_MONITOR_EVIDENCE_ROOT", "./evidence/sidecar"),
        ).resolve(),
        probe_timeout_ms=int(os.environ.get("GEO_MONITOR_PROBE_TIMEOUT_MS", "120000")),
        probe_rate_limit_per_minute=int(
            os.environ.get("GEO_MONITOR_PROBE_RATE_PER_MIN", "6"),
        ),
        session_rate_limit_per_minute=int(
            os.environ.get("GEO_MONITOR_SESSION_RATE_PER_MIN", "30"),
        ),
        default_rate_limit_per_minute=int(
            os.environ.get("GEO_MONITOR_DEFAULT_RATE_PER_MIN", "120"),
        ),
        allow_localhost_without_token=token == "",
    )
