from __future__ import annotations

import json
import time
from datetime import datetime, timezone
from pathlib import Path

from geo_monitor_poc.models import AccountConfig, PlatformId, ProbeResult


def ensure_dir(path: Path) -> Path:
    """
    确保目录存在。

    @param path 目录路径
    @return 同一目录路径
    """
    path.mkdir(parents=True, exist_ok=True)
    return path


def build_run_dir(base_dir: Path, platform: PlatformId, account_id: str) -> Path:
    """
    构造单次运行证据目录。

    @param base_dir 证据根目录
    @param platform 平台标识
    @param account_id 账号 ID
    @return 运行目录路径
    """
    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    return ensure_dir(base_dir / platform.value / account_id / stamp)


def load_accounts(path: Path) -> list[AccountConfig]:
    """
    从 JSON 文件加载账号配置。

    @param path 配置文件路径
    @return 账号配置列表
    """
    payload = json.loads(path.read_text(encoding="utf-8"))
    accounts: list[AccountConfig] = []

    for row in payload.get("accounts", []):
        accounts.append(
            AccountConfig(
                id=str(row["id"]),
                platform=PlatformId(str(row["platform"])),
                label=str(row.get("label", row["id"])),
                profile_dir=str(row["profile_dir"]),
                proxy=str(row.get("proxy", "")),
                locale=str(row.get("locale", "zh-CN")),
                timezone_id=str(row.get("timezone_id", "Asia/Shanghai")),
                enabled=bool(row.get("enabled", True)),
            )
        )

    return accounts


def load_prompts(path: Path) -> list[dict[str, str]]:
    """
    从 JSON 文件加载问题集。

    @param path 问题集文件路径
    @return 问题列表
    """
    payload = json.loads(path.read_text(encoding="utf-8"))
    prompts: list[dict[str, str]] = []

    for row in payload.get("prompts", []):
        prompts.append(
            {
                "id": str(row["id"]),
                "text": str(row["text"]),
                "intent": str(row.get("intent", "generic")),
            }
        )

    return prompts


def find_account(accounts: list[AccountConfig], platform: PlatformId) -> AccountConfig | None:
    """
    查找某平台第一个启用的账号配置。

    @param accounts 账号列表
    @param platform 平台标识
    @return 匹配账号或 None
    """
    for account in accounts:
        if account.platform == platform and account.enabled:
            return account

    return None


def now_ms() -> int:
    """
    返回当前毫秒时间戳。

    @return 毫秒时间戳
    """
    return int(time.time() * 1000)
