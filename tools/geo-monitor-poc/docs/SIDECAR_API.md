# GEO Monitor Scrapling Sidecar API（v1）

Laravel 通过 HTTP + Bearer Token 调用本服务，不直接操作 Playwright。

- 默认监听：`127.0.0.1:8765`
- 协议版本：`v1`
- 内容类型：`application/json`

## 鉴权

所有 `/v1/*` 路由需要：

```http
Authorization: Bearer <GEO_MONITOR_SIDECAR_TOKEN>
```

或：

```http
X-GEO-Monitor-Token: <GEO_MONITOR_SIDECAR_TOKEN>
```

未配置 `GEO_MONITOR_SIDECAR_TOKEN` 时，仅允许本机 `127.0.0.1` 访问（开发模式）。

## 响应信封

成功：

```json
{
  "ok": true,
  "data": { }
}
```

失败：

```json
{
  "ok": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "人类可读说明",
    "details": {}
  }
}
```

## 错误码

| HTTP | code | 说明 |
| ---: | --- | --- |
| 400 | `VALIDATION_ERROR` | 请求体字段非法 |
| 401 | `UNAUTHORIZED` | 缺少或错误的 Token |
| 404 | `NOT_FOUND` | 平台/账号/证据不存在 |
| 408 | `PROBE_TIMEOUT` | 单次探测超时 |
| 429 | `RATE_LIMITED` | 超过限频 |
| 500 | `INTERNAL_ERROR` | 未预期异常 |
| 503 | `BROWSER_UNAVAILABLE` | Chromium/Playwright 不可用 |
| 503 | `PLATFORM_BUSY` | 同账号并发探测（预留） |

业务结果（HTTP 200）仍可能 `data.status=captcha_required` 等，由 Laravel 按 `ProbeStatus` 处理。

## 限频（默认）

| 路由 | 限制 |
| --- | --- |
| `POST /v1/probe` | 每 Token 6 次/分钟（浏览器重操作） |
| `GET /v1/platforms/*/session` | 30 次/分钟 |
| 其他 | 120 次/分钟 |

环境变量可覆盖，见 `geo_monitor_poc/sidecar/config.py`。

## 端点

### `GET /health`

无需鉴权。返回 sidecar 与浏览器依赖状态。

```json
{
  "ok": true,
  "data": {
    "service": "geo-monitor-sidecar",
    "version": "0.1.0",
    "auth_required": true,
    "platforms": ["doubao", "deepseek", "yuanbao"]
  }
}
```

### `GET /v1/platforms`

列出支持的平台与 selector 版本。

### `GET /v1/platforms/{platform}/session`

检查账号 profile 登录态（打开聊天页 + DOM 检测，不提问）。

查询参数：

- `account_id`（必填）
- `accounts_file`（可选，默认 sidecar 配置的 accounts 路径）

响应 `data`：

```json
{
  "platform": "doubao",
  "account_id": "doubao_account_01",
  "login_status": "logged_in",
  "selector_version": "2026-06-03-poc-v5-doubao-fast",
  "duration_ms": 3200
}
```

### `POST /v1/probe`

执行单次探测（等价 CLI `run_probe`）。

请求体：

```json
{
  "platform": "doubao",
  "account_id": "doubao_account_01",
  "prompt_id": "run_42",
  "prompt_text": "企业知识库有哪些方案？",
  "headless": true,
  "production": false,
  "skip_login_check": false,
  "evidence_subdir": "laravel-run-42",
  "timeout_ms": 120000,
  "resource": {
    "account_id": "doubao_account_01",
    "profile_id": "doubao_account_01",
    "proxy_id": "",
    "proxy_region": "",
    "fingerprint_summary": ""
  }
}
```

- `production=true` 等价 `--production`（无头 + 非交互 + 验证码告警）
- `resource` 字段回传到 `data.meta.resource`，供 Laravel 审计

响应 `data`：与 `ProbeResult.to_dict()` 一致。

### `GET /v1/evidence`

下载证据文件（截图/HTML）。

查询参数：

- `path`：必须为 sidecar 证据根目录下的相对路径（防目录穿越）

## 启动

```bash
export GEO_MONITOR_SIDECAR_TOKEN="your-secret"
export GEO_MONITOR_ACCOUNTS_FILE="./accounts.json"
export GEO_MONITOR_EVIDENCE_ROOT="./evidence/sidecar"

./run.sh serve --host 127.0.0.1 --port 8765
```

## Laravel 对接（Stage 3）

`ScraplingBridgeClient` 仅依赖本契约，示例：

```php
$response = Http::withToken(config('geoflow.geo_monitor.sidecar_token'))
    ->timeout(150)
    ->post($baseUrl.'/v1/probe', $payload);
```

配置项建议：`geoflow.geo_monitor.sidecar_url`、`sidecar_token`、`probe_timeout_seconds`。
