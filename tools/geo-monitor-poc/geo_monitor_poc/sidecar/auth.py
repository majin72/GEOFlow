from __future__ import annotations

from geo_monitor_poc.sidecar.config import SidecarSettings
from geo_monitor_poc.sidecar.errors import SidecarErrorCode, error_body


def extract_token(headers: dict[str, str]) -> str:
    """
    从请求头解析 Bearer 或自定义 Token。

    @param headers 小写键名的请求头字典
    @return Token 字符串，无则空
    """
    auth = headers.get("authorization", "")
    if auth.lower().startswith("bearer "):
        return auth[7:].strip()

    return headers.get("x-geo-monitor-token", "").strip()


def authorize(
    client_host: str,
    headers: dict[str, str],
    settings: SidecarSettings,
) -> dict[str, object] | None:
    """
    校验请求鉴权；失败时返回错误信封。

    @param client_host 客户端 IP
    @param headers 请求头（键小写）
    @param settings sidecar 配置
    @return None 表示通过；否则为错误 JSON 体（调用方需配 HTTP 状态码）
    """
    if not settings.token:
        if settings.allow_localhost_without_token and client_host in {"127.0.0.1", "::1"}:
            return None
        return error_body(
            SidecarErrorCode.UNAUTHORIZED,
            "未配置 GEO_MONITOR_SIDECAR_TOKEN，且非本机访问",
        )

    provided = extract_token(headers)
    if provided != settings.token:
        return error_body(SidecarErrorCode.UNAUTHORIZED, "Token 无效或缺失")

    return None
