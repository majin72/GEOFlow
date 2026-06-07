from __future__ import annotations

from enum import Enum
from typing import Any


class SidecarErrorCode(str, Enum):
    """Sidecar HTTP 层错误码（与 ProbeStatus 业务状态分离）。"""

    VALIDATION_ERROR = "VALIDATION_ERROR"
    UNAUTHORIZED = "UNAUTHORIZED"
    NOT_FOUND = "NOT_FOUND"
    PROBE_TIMEOUT = "PROBE_TIMEOUT"
    RATE_LIMITED = "RATE_LIMITED"
    INTERNAL_ERROR = "INTERNAL_ERROR"
    BROWSER_UNAVAILABLE = "BROWSER_UNAVAILABLE"
    PLATFORM_BUSY = "PLATFORM_BUSY"


HTTP_STATUS_BY_CODE: dict[SidecarErrorCode, int] = {
    SidecarErrorCode.VALIDATION_ERROR: 400,
    SidecarErrorCode.UNAUTHORIZED: 401,
    SidecarErrorCode.NOT_FOUND: 404,
    SidecarErrorCode.PROBE_TIMEOUT: 408,
    SidecarErrorCode.RATE_LIMITED: 429,
    SidecarErrorCode.INTERNAL_ERROR: 500,
    SidecarErrorCode.BROWSER_UNAVAILABLE: 503,
    SidecarErrorCode.PLATFORM_BUSY: 503,
}


def error_body(
    code: SidecarErrorCode,
    message: str,
    *,
    details: dict[str, Any] | None = None,
) -> dict[str, Any]:
    """
    构造标准错误 JSON 信封。

    @param code 错误码
    @param message 人类可读说明
    @param details 附加字段
    @return 可序列化字典
    """
    return {
        "ok": False,
        "error": {
            "code": code.value,
            "message": message,
            "details": details or {},
        },
    }


def success_body(data: dict[str, Any] | list[Any]) -> dict[str, Any]:
    """
    构造标准成功 JSON 信封。

    @param data 业务载荷
    @return 可序列化字典
    """
    return {"ok": True, "data": data}
