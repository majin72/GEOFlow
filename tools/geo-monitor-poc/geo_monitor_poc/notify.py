from __future__ import annotations

import json
import os
import sys
import urllib.error
import urllib.request
from pathlib import Path

from geo_monitor_poc.models import ProbeResult


def build_captcha_alert_payload(result: ProbeResult, *, stage: str = "") -> dict[str, object]:
    """
    构造验证码告警 JSON 载荷，便于队列/Webhook 消费。

    @param result 探测结果
    @param stage 验证码出现阶段
    @return 可序列化字典
    """
    payload = result.to_dict()
    payload["alert_type"] = "captcha_required"
    if stage:
        payload["captcha_stage"] = stage
    return payload


def emit_captcha_alert(result: ProbeResult, *, stage: str = "") -> None:
    """
    生产环境验证码告警：终端醒目标记 + 可选 Webhook / 告警文件。

    环境变量：
    - GEO_MONITOR_WEBHOOK_URL：POST JSON 告警
    - GEO_MONITOR_ALERT_FILE：追加一行 JSON（供 cron/监控扫尾）

    @param result 探测结果（status 应为 captcha_required）
    @param stage 验证码出现阶段
    """
    payload = build_captcha_alert_payload(result, stage=stage)
    evidence = result.evidence

    print("", file=sys.stderr)
    print("!" * 60, file=sys.stderr)
    print(
        f"【生产告警】{result.platform.value} / {result.account_id} 需要人工过验证码",
        file=sys.stderr,
    )
    if stage:
        print(f"阶段: {stage}", file=sys.stderr)
    if evidence.screenshot_path:
        print(f"截图: {evidence.screenshot_path}", file=sys.stderr)
    print(
        "处理: 在同一 profile 上运行可见浏览器 "
        f"./run.sh captcha --platform {result.platform.value} "
        f"--account-id {result.account_id}",
        file=sys.stderr,
    )
    print("!" * 60, file=sys.stderr)
    print("", file=sys.stderr)

    webhook_url = os.environ.get("GEO_MONITOR_WEBHOOK_URL", "").strip()
    if webhook_url:
        _post_webhook(webhook_url, payload)

    alert_file = os.environ.get("GEO_MONITOR_ALERT_FILE", "").strip()
    if alert_file:
        _append_alert_file(Path(alert_file), payload)


def _post_webhook(url: str, payload: dict[str, object]) -> None:
    """
    向 Webhook 发送验证码告警。

    @param url Webhook 地址
    @param payload JSON 载荷
    """
    body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    request = urllib.request.Request(
        url,
        data=body,
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=15) as response:
            if response.status >= 400:
                print(f"Webhook 返回异常状态: {response.status}", file=sys.stderr)
    except urllib.error.URLError as exc:
        print(f"Webhook 发送失败: {exc}", file=sys.stderr)


def _append_alert_file(path: Path, payload: dict[str, object]) -> None:
    """
    将告警 JSON 追加写入文件。

    @param path 告警文件路径
    @param payload JSON 载荷
    """
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("a", encoding="utf-8") as handle:
        handle.write(json.dumps(payload, ensure_ascii=False) + "\n")
