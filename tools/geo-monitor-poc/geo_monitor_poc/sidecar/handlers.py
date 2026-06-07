from __future__ import annotations

import json
import mimetypes
import os
from pathlib import Path
from typing import Any
from urllib.parse import parse_qs, unquote, urlparse

from geo_monitor_poc.adapters import create_adapter
from geo_monitor_poc.browser import fetch_with_action, open_platform_session
from geo_monitor_poc.config import DEFAULT_PROBE_TIMEOUT_MS, PLATFORM_SELECTORS
from geo_monitor_poc.models import PlatformId
from geo_monitor_poc.probe_runner import run_probe
from geo_monitor_poc.sidecar.config import SidecarSettings
from geo_monitor_poc.sidecar.errors import (
    HTTP_STATUS_BY_CODE,
    SidecarErrorCode,
    error_body,
    success_body,
)
from geo_monitor_poc.maintenance_session import (
    complete_session,
    get_session,
    session_to_dict,
    start_session,
)
from geo_monitor_poc.utils import build_run_dir, find_account, load_accounts, now_ms


def parse_json_body(raw: bytes) -> dict[str, Any]:
    """
    解析 JSON 请求体。

    @param raw 原始字节
    @return 字典
    @raises ValueError 解析失败
    """
    if not raw:
        raise ValueError("请求体为空")

    payload = json.loads(raw.decode("utf-8"))
    if not isinstance(payload, dict):
        raise ValueError("请求体必须是 JSON 对象")

    return payload


def resolve_account(
    settings: SidecarSettings,
    platform: PlatformId,
    account_id: str,
    accounts_file: str | None,
) -> tuple[Any | None, dict[str, object] | None]:
    """
    加载并匹配账号配置。

    @param settings sidecar 配置
    @param platform 平台
    @param account_id 账号 ID
    @param accounts_file 可选账号文件覆盖路径
    @return (AccountConfig, error_body)
    """
    path = Path(accounts_file).resolve() if accounts_file else settings.accounts_file
    if not path.exists():
        return None, error_body(
            SidecarErrorCode.NOT_FOUND,
            f"账号配置不存在: {path}",
        )

    accounts = load_accounts(path)
    if account_id:
        account = next((item for item in accounts if item.id == account_id), None)
        if account is None:
            return None, error_body(
                SidecarErrorCode.NOT_FOUND,
                f"未找到账号: {account_id}",
            )
        if account.platform != platform:
            return None, error_body(
                SidecarErrorCode.VALIDATION_ERROR,
                f"账号 {account_id} 不属于平台 {platform.value}",
            )
        return account, None

    account = find_account(accounts, platform)
    if account is None:
        return None, error_body(
            SidecarErrorCode.NOT_FOUND,
            f"平台 {platform.value} 无启用账号",
        )

    return account, None


def handle_health() -> tuple[int, dict[str, Any]]:
    """
    健康检查。

    @return (http_status, body)
    """
    browser_ok = True
    browser_note = "ok"
    try:
        executable = os.environ.get("GEO_MONITOR_CHROMIUM_EXECUTABLE", "").strip()
        if executable != "":
            if not Path(executable).exists():
                browser_ok = False
                browser_note = f"chromium executable missing: {executable}"
        else:
            from playwright.sync_api import sync_playwright  # noqa: PLC0415

            with sync_playwright() as playwright:
                bundled = playwright.chromium.executable_path
                if not Path(bundled).exists():
                    browser_ok = False
                    browser_note = "chromium executable missing"
    except Exception as exc:  # noqa: BLE001
        browser_ok = False
        browser_note = str(exc)

    from geo_monitor_poc import __version__  # noqa: PLC0415

    return 200, success_body(
        {
            "service": "geo-monitor-sidecar",
            "version": __version__,
            "browser_ok": browser_ok,
            "browser_note": browser_note,
            "platforms": [item.value for item in PlatformId],
        },
    )


def handle_list_platforms() -> tuple[int, dict[str, Any]]:
    """
    列出平台与 selector 版本。

    @return (http_status, body)
    """
    items = []
    for platform, selectors in PLATFORM_SELECTORS.items():
        items.append(
            {
                "id": platform.value,
                "chat_url": selectors.chat_url,
                "selector_version": selectors.selector_version,
                "guest_mode_allowed": selectors.guest_mode_allowed,
            },
        )

    return 200, success_body({"platforms": items})


def handle_session_check(
    settings: SidecarSettings,
    platform_raw: str,
    query: dict[str, list[str]],
) -> tuple[int, dict[str, Any]]:
    """
    检查平台账号登录态。

    @param settings sidecar 配置
    @param platform_raw 平台路径段
    @param query 查询参数
    @return (http_status, body)
    """
    try:
        platform = PlatformId(platform_raw.strip().lower())
    except ValueError:
        return 400, error_body(SidecarErrorCode.VALIDATION_ERROR, f"未知平台: {platform_raw}")

    account_id = (query.get("account_id") or [""])[0].strip()
    accounts_file = (query.get("accounts_file") or [None])[0]

    account, err = resolve_account(settings, platform, account_id, accounts_file)
    if err is not None:
        return HTTP_STATUS_BY_CODE[SidecarErrorCode.NOT_FOUND], err

    adapter = create_adapter(account)
    started = now_ms()
    state: dict[str, object] = {"login_status": "unknown", "error": ""}

    def page_action(page) -> None:
        page.wait_for_timeout(1_500)
        adapter.prepare_page(page)
        state["login_status"] = adapter.detect_login_status(page).value

    try:
        with open_platform_session(account, headless=True, timeout_ms=60_000) as session:
            fetch_with_action(
                session,
                adapter.selectors.chat_url,
                page_action,
                wait_selector=adapter.selectors.page_ready_selector,
                wait_ms=3_000,
            )
    except Exception as exc:  # noqa: BLE001
        message = str(exc)
        if "Executable doesn't exist" in message or "playwright install" in message.lower():
            return (
                HTTP_STATUS_BY_CODE[SidecarErrorCode.BROWSER_UNAVAILABLE],
                error_body(SidecarErrorCode.BROWSER_UNAVAILABLE, message),
            )
        return 500, error_body(SidecarErrorCode.INTERNAL_ERROR, message)

    return 200, success_body(
        {
            "platform": platform.value,
            "account_id": account.id,
            "login_status": state["login_status"],
            "selector_version": adapter.selectors.selector_version,
            "duration_ms": now_ms() - started,
        },
    )


def handle_probe(settings: SidecarSettings, payload: dict[str, Any]) -> tuple[int, dict[str, Any]]:
    """
    执行单次探测。

    @param settings sidecar 配置
    @param payload 请求 JSON
    @return (http_status, body)
    """
    platform_raw = str(payload.get("platform", "")).strip().lower()
    prompt_text = str(payload.get("prompt_text", "")).strip()
    prompt_id = str(payload.get("prompt_id", "")).strip() or f"probe_{now_ms()}"

    if platform_raw == "" or prompt_text == "":
        return 400, error_body(
            SidecarErrorCode.VALIDATION_ERROR,
            "platform 与 prompt_text 必填",
        )

    try:
        platform = PlatformId(platform_raw)
    except ValueError:
        return 400, error_body(SidecarErrorCode.VALIDATION_ERROR, f"未知平台: {platform_raw}")

    account_id = str(payload.get("account_id", "")).strip()
    accounts_file = payload.get("accounts_file")
    accounts_file_str = str(accounts_file) if accounts_file else None

    account, err = resolve_account(settings, platform, account_id, accounts_file_str)
    if err is not None:
        code = SidecarErrorCode.NOT_FOUND
        return HTTP_STATUS_BY_CODE[code], err

    production = bool(payload.get("production", False))
    headless = bool(payload.get("headless", False)) or production
    interactive = not headless and not bool(payload.get("no_interactive", False)) and not production
    skip_login_check = bool(payload.get("skip_login_check", False))

    timeout_ms = int(payload.get("timeout_ms", settings.probe_timeout_ms))
    if timeout_ms < 10_000:
        timeout_ms = DEFAULT_PROBE_TIMEOUT_MS

    evidence_subdir = str(payload.get("evidence_subdir", "")).strip() or prompt_id
    evidence_dir = build_run_dir(settings.evidence_root, platform, account.id)
    evidence_dir = evidence_dir / evidence_subdir
    evidence_dir.mkdir(parents=True, exist_ok=True)

    resource = payload.get("resource")
    resource_meta = resource if isinstance(resource, dict) else {}

    try:
        result = run_probe(
            account,
            prompt_id=prompt_id,
            prompt_text=prompt_text,
            evidence_dir=evidence_dir,
            headless=headless,
            skip_login_check=skip_login_check,
            interactive=interactive,
        )
    except Exception as exc:  # noqa: BLE001
        message = str(exc)
        if "Executable doesn't exist" in message:
            return (
                HTTP_STATUS_BY_CODE[SidecarErrorCode.BROWSER_UNAVAILABLE],
                error_body(SidecarErrorCode.BROWSER_UNAVAILABLE, message),
            )
        return 500, error_body(SidecarErrorCode.INTERNAL_ERROR, message)

    if result.duration_ms > timeout_ms:
        return (
            HTTP_STATUS_BY_CODE[SidecarErrorCode.PROBE_TIMEOUT],
            error_body(
                SidecarErrorCode.PROBE_TIMEOUT,
                f"探测耗时 {result.duration_ms}ms 超过 timeout_ms={timeout_ms}",
            ),
        )

    data = result.to_dict()
    data["meta"] = {**data.get("meta", {}), "resource": resource_meta}
    return 200, success_body(data)


def handle_maintenance_session_start(
    settings: SidecarSettings,
    payload: dict[str, Any],
) -> tuple[int, dict[str, Any]]:
    """
    启动交互式 profile 维护（可见浏览器，等待后台 complete 保存）。

    @param settings sidecar 配置
    @param payload 请求 JSON
    @return (http_status, body)
    """
    platform_raw = str(payload.get("platform", "")).strip().lower()
    account_id = str(payload.get("account_id", "")).strip()
    mode = str(payload.get("mode", "login")).strip().lower()

    if platform_raw == "" or account_id == "":
        return 400, error_body(
            SidecarErrorCode.VALIDATION_ERROR,
            "platform 与 account_id 必填",
        )

    if mode not in {"login", "captcha"}:
        return 400, error_body(
            SidecarErrorCode.VALIDATION_ERROR,
            "mode 仅支持 login 或 captcha",
        )

    try:
        platform = PlatformId(platform_raw)
    except ValueError:
        return 400, error_body(SidecarErrorCode.VALIDATION_ERROR, f"未知平台: {platform_raw}")

    accounts_file = payload.get("accounts_file")
    accounts_file_str = str(accounts_file) if accounts_file else None

    account, err = resolve_account(settings, platform, account_id, accounts_file_str)
    if err is not None:
        return HTTP_STATUS_BY_CODE[SidecarErrorCode.NOT_FOUND], err

    try:
        session = start_session(account, mode=mode)
    except RuntimeError as exc:
        return 409, error_body(SidecarErrorCode.PLATFORM_BUSY, str(exc))
    except Exception as exc:  # noqa: BLE001
        message = str(exc)
        if "Executable doesn't exist" in message:
            return (
                HTTP_STATUS_BY_CODE[SidecarErrorCode.BROWSER_UNAVAILABLE],
                error_body(SidecarErrorCode.BROWSER_UNAVAILABLE, message),
            )
        return 500, error_body(SidecarErrorCode.INTERNAL_ERROR, message)

    return 200, success_body(session_to_dict(session))


def handle_maintenance_session_get(session_id: str) -> tuple[int, dict[str, Any]]:
    """
    查询维护会话状态。

    @param session_id 会话 ID
    @return (http_status, body)
    """
    session = get_session(session_id)
    if session is None:
        return 404, error_body(SidecarErrorCode.NOT_FOUND, f"维护会话不存在: {session_id}")

    return 200, success_body(session_to_dict(session))


def handle_maintenance_session_complete(session_id: str) -> tuple[int, dict[str, Any]]:
    """
    完成维护会话：关闭浏览器并保存 profile。

    @param session_id 会话 ID
    @return (http_status, body)
    """
    try:
        session = complete_session(session_id)
    except KeyError:
        return 404, error_body(SidecarErrorCode.NOT_FOUND, f"维护会话不存在: {session_id}")
    except RuntimeError as exc:
        return 409, error_body(SidecarErrorCode.VALIDATION_ERROR, str(exc))
    except Exception as exc:  # noqa: BLE001
        return 500, error_body(SidecarErrorCode.INTERNAL_ERROR, str(exc))

    return 200, success_body(session_to_dict(session))


def handle_evidence_download(
    settings: SidecarSettings,
    query: dict[str, list[str]],
) -> tuple[int, dict[str, Any] | bytes, str]:
    """
    下载证据文件。

    @param settings sidecar 配置
    @param query 查询参数
    @return (status, body_or_bytes, content_type)
    """
    rel_path = unquote((query.get("path") or [""])[0]).strip()
    if rel_path == "" or ".." in rel_path or rel_path.startswith("/"):
        return 400, error_body(SidecarErrorCode.VALIDATION_ERROR, "path 非法"), "application/json"

    target = (settings.evidence_root / rel_path).resolve()
    if not str(target).startswith(str(settings.evidence_root)):
        return 400, error_body(SidecarErrorCode.VALIDATION_ERROR, "path 越界"), "application/json"

    if not target.is_file():
        return 404, error_body(SidecarErrorCode.NOT_FOUND, "证据文件不存在"), "application/json"

    content_type = mimetypes.guess_type(target.name)[0] or "application/octet-stream"
    return 200, target.read_bytes(), content_type


def route_request(
    method: str,
    path: str,
    *,
    settings: SidecarSettings,
    query: str,
    body: bytes,
) -> tuple[int, dict[str, Any] | bytes, str]:
    """
    分发 HTTP 请求。

    @param method HTTP 方法
    @param path 路径
    @param settings 配置
    @param query 原始 query string
    @param body 请求体
    @return (status, payload, content_type)
    """
    parsed = urlparse(path)
    clean_path = parsed.path.rstrip("/") or "/"
    query_map = parse_qs(parsed.query or query)

    if method == "GET" and clean_path == "/health":
        status, payload = handle_health()
        return status, payload, "application/json"

    if clean_path == "/v1/platforms" and method == "GET":
        status, payload = handle_list_platforms()
        return status, payload, "application/json"

    if clean_path.startswith("/v1/platforms/") and clean_path.endswith("/session") and method == "GET":
        platform = clean_path.removeprefix("/v1/platforms/").removesuffix("/session")
        status, payload = handle_session_check(settings, platform, query_map)
        return status, payload, "application/json"

    if clean_path == "/v1/probe" and method == "POST":
        try:
            payload = parse_json_body(body)
        except (ValueError, json.JSONDecodeError) as exc:
            return 400, error_body(SidecarErrorCode.VALIDATION_ERROR, str(exc)), "application/json"
        status, payload = handle_probe(settings, payload)
        return status, payload, "application/json"

    if clean_path == "/v1/evidence" and method == "GET":
        status, payload, content_type = handle_evidence_download(settings, query_map)
        return status, payload, content_type

    if clean_path == "/v1/maintenance/sessions" and method == "POST":
        try:
            payload = parse_json_body(body)
        except (ValueError, json.JSONDecodeError) as exc:
            return 400, error_body(SidecarErrorCode.VALIDATION_ERROR, str(exc)), "application/json"
        status, payload = handle_maintenance_session_start(settings, payload)
        return status, payload, "application/json"

    if clean_path.startswith("/v1/maintenance/sessions/") and method == "GET":
        session_id = clean_path.removeprefix("/v1/maintenance/sessions/").strip("/")
        status, payload = handle_maintenance_session_get(session_id)
        return status, payload, "application/json"

    if clean_path.startswith("/v1/maintenance/sessions/") and clean_path.endswith("/complete") and method == "POST":
        session_id = clean_path.removeprefix("/v1/maintenance/sessions/").removesuffix("/complete").strip("/")
        status, payload = handle_maintenance_session_complete(session_id)
        return status, payload, "application/json"

    return 404, error_body(SidecarErrorCode.NOT_FOUND, f"未知路由: {method} {clean_path}"), "application/json"
