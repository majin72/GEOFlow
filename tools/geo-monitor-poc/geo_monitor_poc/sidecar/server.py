from __future__ import annotations

import json
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any

from geo_monitor_poc.sidecar.auth import authorize, extract_token
from geo_monitor_poc.sidecar.config import SidecarSettings, load_settings
from geo_monitor_poc.sidecar.errors import HTTP_STATUS_BY_CODE, SidecarErrorCode, error_body
from geo_monitor_poc.sidecar.handlers import route_request
from geo_monitor_poc.sidecar.rate_limit import TokenBucketRateLimiter


class SidecarHTTPRequestHandler(BaseHTTPRequestHandler):
    """GEO Monitor sidecar HTTP 处理器。"""

    settings: SidecarSettings
    rate_limiter: TokenBucketRateLimiter

    def log_message(self, format: str, *args: object) -> None:  # noqa: A003
        """
        将访问日志写到 stderr。

        @param format 日志格式
        @param args 格式参数
        """
        print(f"[sidecar] {self.address_string()} - {format % args}")

    def _read_headers(self) -> dict[str, str]:
        """
        读取小写键名的请求头。

        @return 请求头字典
        """
        return {key.lower(): value for key, value in self.headers.items()}

    def _write_json(self, status: int, payload: dict[str, Any]) -> None:
        """
        输出 JSON 响应。

        @param status HTTP 状态码
        @param payload 响应体
        """
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _write_bytes(self, status: int, body: bytes, content_type: str) -> None:
        """
        输出二进制响应。

        @param status HTTP 状态码
        @param body 响应字节
        @param content_type Content-Type
        """
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _rate_limit_key(self, group: str) -> str:
        """
        构造限频键。

        @param group 路由组名
        @return 限频键
        """
        token = extract_token(self._read_headers()) or self.client_address[0]
        return f"{group}:{token}"

    def _check_rate_limit(self, path: str, headers: dict[str, str]) -> dict[str, Any] | None:
        """
        按路由检查限频。

        @param path 请求路径
        @param headers 请求头
        @return 错误信封或 None
        """
        if path == "/v1/probe":
            limit = self.settings.probe_rate_limit_per_minute
            group = "probe"
        elif path.endswith("/session"):
            limit = self.settings.session_rate_limit_per_minute
            group = "session"
        else:
            limit = self.settings.default_rate_limit_per_minute
            group = "default"

        return self.rate_limiter.check(
            self._rate_limit_key(group),
            limit=limit,
        )

    def _handle(self) -> None:
        """统一处理 GET/POST。"""
        path = self.path.split("?", 1)[0]
        headers = self._read_headers()
        client_host = self.client_address[0]

        if path != "/health":
            auth_error = authorize(client_host, headers, self.settings)
            if auth_error is not None:
                self._write_json(HTTP_STATUS_BY_CODE[SidecarErrorCode.UNAUTHORIZED], auth_error)
                return

            rate_error = self._check_rate_limit(path, headers)
            if rate_error is not None:
                self._write_json(HTTP_STATUS_BY_CODE[SidecarErrorCode.RATE_LIMITED], rate_error)
                return

        length = int(self.headers.get("Content-Length", "0") or "0")
        body = self.rfile.read(length) if length > 0 else b""

        status, payload, content_type = route_request(
            self.command,
            self.path,
            settings=self.settings,
            query="",
            body=body,
        )

        if content_type == "application/json" and isinstance(payload, dict):
            self._write_json(status, payload)
            return

        if isinstance(payload, bytes):
            self._write_bytes(status, payload, content_type)
            return

        self._write_json(500, error_body(SidecarErrorCode.INTERNAL_ERROR, "响应类型异常"))

    def do_GET(self) -> None:
        """处理 GET。"""
        self._handle()

    def do_POST(self) -> None:
        """处理 POST。"""
        self._handle()


def run_server(settings: SidecarSettings | None = None) -> None:
    """
    启动 sidecar HTTP 服务（阻塞）。

    @param settings 可选配置，默认从环境变量加载
    """
    resolved = settings or load_settings()
    SidecarHTTPRequestHandler.settings = resolved
    SidecarHTTPRequestHandler.rate_limiter = TokenBucketRateLimiter()

    server = ThreadingHTTPServer((resolved.host, resolved.port), SidecarHTTPRequestHandler)
    print(f"GEO Monitor sidecar 监听 http://{resolved.host}:{resolved.port}")
    print(f"健康检查: http://{resolved.host}:{resolved.port}/health")
    if resolved.token:
        print("鉴权: 已启用 Bearer Token")
    else:
        print("鉴权: 开发模式（仅 127.0.0.1 免 Token）")

    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nsidecar 已停止")
        server.server_close()
