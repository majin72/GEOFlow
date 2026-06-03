from __future__ import annotations

from datetime import datetime, timezone
from pathlib import Path

from geo_monitor_poc.models import ProbeResult, ProbeStatus


def render_probe_report(results: list[ProbeResult]) -> str:
    """
    将探测结果渲染为 Markdown 报告。

    @param results 探测结果列表
    @return Markdown 文本
    """
    lines: list[str] = [
        "# GEO 引用度 POC 探测报告",
        "",
        f"- 生成时间: {datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S UTC')}",
        f"- 样本数: {len(results)}",
        "",
        "## 汇总",
        "",
        "| 平台 | 问题 | 状态 | 登录态 | 引用数 | 耗时(ms) |",
        "| --- | --- | --- | --- | ---: | ---: |",
    ]

    for result in results:
        lines.append(
            "| {platform} | {prompt} | {status} | {login} | {citations} | {duration} |".format(
                platform=result.platform.value,
                prompt=result.prompt_id,
                status=result.status.value,
                login=result.login_status.value,
                citations=len(result.citations),
                duration=result.duration_ms,
            )
        )

    lines.extend(["", "## 明细", ""])

    for result in results:
        lines.extend(
            [
                f"### {result.platform.value} / {result.prompt_id}",
                "",
                f"- 状态: `{result.status.value}`",
                f"- 登录态: `{result.login_status.value}`",
                f"- 错误: {result.error_message or '-'}",
                f"- 引用数: {len(result.citations)}",
                f"- 截图: `{result.evidence.screenshot_path or '-'}`",
                f"- HTML: `{result.evidence.html_path or '-'}`",
                "",
                "#### 回答摘要",
                "",
                "```text",
                (result.answer_text[:1200] + ("..." if len(result.answer_text) > 1200 else ""))
                if result.answer_text
                else "(empty)",
                "```",
                "",
            ]
        )

        if result.citations:
            lines.append("#### 引用来源")
            lines.append("")
            for citation in result.citations:
                title = citation.title or citation.url
                lines.append(f"- [{title}]({citation.url}) (`{citation.source_type}`)")
            lines.append("")

    feasibility = summarize_feasibility(results)
    lines.extend(["## 平台可采集性结论", ""])
    for platform, verdict in feasibility.items():
        lines.append(f"- **{platform}**: {verdict}")

    lines.append("")
    return "\n".join(lines)


def summarize_feasibility(results: list[ProbeResult]) -> dict[str, str]:
    """
    按平台汇总可采集性结论。

    @param results 探测结果列表
    @return 平台 -> 结论文案
    """
    grouped: dict[str, list[ProbeResult]] = {}
    for result in results:
        grouped.setdefault(result.platform.value, []).append(result)

    verdicts: dict[str, str] = {}
    for platform, items in grouped.items():
        success = sum(1 for item in items if item.status == ProbeStatus.SUCCESS)
        partial = sum(1 for item in items if item.status == ProbeStatus.PARTIAL)
        needs_login = sum(1 for item in items if item.status == ProbeStatus.NEEDS_LOGIN)
        failed = sum(1 for item in items if item.status in {ProbeStatus.FAILED, ProbeStatus.SELECTOR_MISS})

        if needs_login > 0:
            verdicts[platform] = "需先完成登录态验证后再重试"
        elif success > 0:
            verdicts[platform] = f"可采集，{success} 条成功抽取引用，{partial} 条仅文本无引用"
        elif partial > 0:
            verdicts[platform] = "部分可采集，能拿到回答但引用 selector 需继续调优"
        elif failed > 0:
            verdicts[platform] = "当前 selector/页面结构未命中，建议先跑 discover 更新 DOM"
        else:
            verdicts[platform] = "暂无有效样本"

    return verdicts


def save_report(results: list[ProbeResult], output_path: Path) -> Path:
    """
    保存 Markdown 报告到文件。

    @param results 探测结果列表
    @param output_path 输出路径
    @return 输出路径
    """
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(render_probe_report(results), encoding="utf-8")
    return output_path
