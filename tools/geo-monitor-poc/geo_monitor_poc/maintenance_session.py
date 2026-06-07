from __future__ import annotations

import threading
import uuid
from dataclasses import dataclass, field
from typing import Any

from geo_monitor_poc.adapters import create_adapter
from geo_monitor_poc.browser import fetch_with_action, open_platform_session
from geo_monitor_poc.models import PlatformId

# 人工登录/验证码维护最长等待 30 分钟
MAINTENANCE_BROWSER_TIMEOUT_MS = 1_800_000


@dataclass
class MaintenanceSession:
    """后台触发的交互式 profile 维护会话。"""

    session_id: str
    platform: str
    account_id: str
    mode: str
    status: str
    profile_path: str
    chat_url: str
    done_event: threading.Event = field(default_factory=threading.Event)
    error: str = ""
    thread: threading.Thread | None = None


_sessions: dict[str, MaintenanceSession] = {}
_account_index: dict[str, str] = {}
_registry_lock = threading.Lock()


def _session_key(platform: str, account_id: str) -> str:
    """
    构造账号级会话索引键。

    @param platform 平台 code
    @param account_id 账号 external_id
    @return 索引键
    """
    return f"{platform}:{account_id}"


def get_session(session_id: str) -> MaintenanceSession | None:
    """
    按 session_id 获取维护会话。

    @param session_id 会话 ID
    @return 会话或 None
    """
    with _registry_lock:
        return _sessions.get(session_id)


def start_session(account: Any, *, mode: str) -> MaintenanceSession:
    """
    启动交互式维护浏览器（后台线程，等待 complete 信号后保存 profile）。

    @param account AccountConfig
    @param mode login 或 captcha
    @return 新建或复用的会话
    @raises RuntimeError 同账号已有进行中会话
    """
    if mode not in {"login", "captcha"}:
        raise ValueError("mode 仅支持 login 或 captcha")

    platform = account.platform.value
    account_id = account.id
    index_key = _session_key(platform, account_id)

    with _registry_lock:
        existing_id = _account_index.get(index_key)
        if existing_id:
            existing = _sessions.get(existing_id)
            if existing is not None and existing.status in {"opening", "open"}:
                return existing

    adapter = create_adapter(account)
    session = MaintenanceSession(
        session_id=uuid.uuid4().hex,
        platform=platform,
        account_id=account_id,
        mode=mode,
        status="opening",
        profile_path=str(account.profile_dir),
        chat_url=adapter.selectors.chat_url,
    )

    def worker() -> None:
        try:
            def page_action(page) -> None:
                page.wait_for_timeout(2_000)
                if mode == "captcha" and adapter.has_captcha(page):
                    session.status = "open"
                else:
                    session.status = "open"
                session.done_event.wait(timeout=MAINTENANCE_BROWSER_TIMEOUT_MS / 1000)

            with open_platform_session(
                account,
                headless=False,
                timeout_ms=MAINTENANCE_BROWSER_TIMEOUT_MS,
            ) as browser_session:
                fetch_with_action(
                    browser_session,
                    adapter.selectors.chat_url,
                    page_action,
                    wait_selector=adapter.selectors.page_ready_selector,
                    wait_ms=3_000,
                )
        except Exception as exc:  # noqa: BLE001
            session.status = "failed"
            session.error = str(exc)
        else:
            if session.status != "failed":
                session.status = "closed"
        finally:
            with _registry_lock:
                _account_index.pop(index_key, None)

    thread = threading.Thread(target=worker, name=f"geo-maint-{session.session_id[:8]}", daemon=True)
    session.thread = thread

    with _registry_lock:
        _sessions[session.session_id] = session
        _account_index[index_key] = session.session_id

    thread.start()
    return session


def complete_session(session_id: str) -> MaintenanceSession:
    """
    通知维护会话关闭浏览器并保存 profile。

    @param session_id 会话 ID
    @return 更新后的会话
    @raises KeyError 会话不存在
    @raises RuntimeError 会话不可完成
    """
    with _registry_lock:
        session = _sessions.get(session_id)
        if session is None:
            raise KeyError(session_id)

    if session.status not in {"opening", "open"}:
        raise RuntimeError(f"会话状态不可完成: {session.status}")

    session.status = "saving"
    session.done_event.set()

    if session.thread is not None:
        session.thread.join(timeout=120)

    if session.status == "saving":
        session.status = "closed"

    return session


def session_to_dict(session: MaintenanceSession) -> dict[str, Any]:
    """
    将会话序列化为 API 响应字典。

    @param session 维护会话
    @return 可 JSON 序列化的字典
    """
    return {
        "session_id": session.session_id,
        "platform": session.platform,
        "account_id": session.account_id,
        "mode": session.mode,
        "status": session.status,
        "profile_path": session.profile_path,
        "chat_url": session.chat_url,
        "error": session.error or None,
    }
