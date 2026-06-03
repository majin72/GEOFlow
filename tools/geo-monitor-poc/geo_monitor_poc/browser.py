from __future__ import annotations

from pathlib import Path
from typing import Any, Callable

from scrapling.fetchers import DynamicSession

from geo_monitor_poc.models import AccountConfig


def build_proxy_config(proxy: str) -> str | dict[str, str] | None:
    """
    构造 Scrapling 可接受的 proxy 参数。

    @param proxy 代理 URL，例如 http://user:pass@host:port
    @return Scrapling proxy 参数
    """
    cleaned = proxy.strip()
    if cleaned == "":
        return None

    return cleaned


def open_platform_session(
    account: AccountConfig,
    *,
    headless: bool,
    timeout_ms: int,
) -> DynamicSession:
    """
    打开带持久化 profile 的浏览器会话。

    @param account 账号配置
    @param headless 是否无头模式
    @param timeout_ms 页面超时毫秒
    @return DynamicSession 实例（需 with 上下文使用）
    """
    profile_dir = Path(account.profile_dir).expanduser().resolve()
    profile_dir.mkdir(parents=True, exist_ok=True)

    proxy = build_proxy_config(account.proxy)

    return DynamicSession(
        headless=headless,
        disable_resources=False,
        network_idle=False,
        timeout=timeout_ms,
        locale=account.locale,
        timezone_id=account.timezone_id,
        user_data_dir=str(profile_dir),
        proxy=proxy,
        real_chrome=False,
    )


def fetch_with_action(
    session: DynamicSession,
    url: str,
    page_action: Callable[[Any], None],
    *,
    wait_selector: str = "",
    wait_ms: int = 0,
):
    """
    使用 page_action 执行浏览器自动化并返回 Scrapling Response。

    @param session 浏览器会话
    @param url 目标 URL
    @param page_action Playwright page 回调
    @param wait_selector 可选等待选择器
    @param wait_ms 回答完成后额外等待毫秒
    @return Scrapling Response
    """
    kwargs: dict[str, Any] = {
        "page_action": page_action,
        "load_dom": True,
    }

    if wait_selector.strip() != "":
        kwargs["wait_selector"] = wait_selector.strip()
        kwargs["wait_selector_state"] = "visible"

    if wait_ms > 0:
        kwargs["wait"] = wait_ms

    return session.fetch(url, **kwargs)
