from __future__ import annotations

import json
import re
from pathlib import Path
from urllib.parse import urlparse

from geo_monitor_poc.models import CitationRecord

URL_PATTERN = re.compile(r"https?://[^\s\]\)\"'<>]+", re.IGNORECASE)


def normalize_url(url: str) -> str:
    """
    规范化 URL，去掉末尾标点。

    @param url 原始 URL
    @return 规范化后的 URL
    """
    cleaned = url.strip().rstrip(".,;)]}\"'")
    return cleaned


CITATION_EXCLUDED_HOST_FRAGMENTS = (
    "doubao.com",
    "doubal.com",
    "byteimg.com",
    "byteacctimg.com",
    "ibytedapm.com",
    "bytedance.com",
    "byteacct.com",
    "snssdk.com",
)


def is_citation_source_url(url: str) -> bool:
    """
    判断 URL 是否像外部引用来源（排除豆包 CDN、统计与站内资源）。

    @param url 待检测 URL
    @return 是否可作为 GEO 引用来源
    """
    if not is_http_url(url):
        return False

    host = urlparse(normalize_url(url)).netloc.lower()
    return not any(fragment in host for fragment in CITATION_EXCLUDED_HOST_FRAGMENTS)


def is_http_url(value: str) -> bool:
    """
    判断字符串是否为 http(s) URL。

    @param value 待检测字符串
    @return 是否为 http(s) URL
    """
    try:
        parsed = urlparse(value)
    except ValueError:
        return False

    return parsed.scheme in {"http", "https"} and bool(parsed.netloc)


def dedupe_citations(items: list[CitationRecord]) -> list[CitationRecord]:
    """
    按 URL 去重引用列表，保留首次出现顺序。

    @param items 原始引用列表
    @return 去重后的引用列表
    """
    seen: set[str] = set()
    result: list[CitationRecord] = []

    for item in items:
        key = normalize_url(item.url).lower()
        if key in seen:
            continue
        seen.add(key)
        result.append(item)

    return result


def extract_urls_from_text(text: str) -> list[str]:
    """
    从纯文本中提取 URL。

    @param text 回答文本
    @return URL 列表
    """
    return [normalize_url(match.group(0)) for match in URL_PATTERN.finditer(text or "")]


def extract_citations_from_links(
    links: list[dict[str, str]],
    *,
    source_type: str = "link",
) -> list[CitationRecord]:
    """
    从链接字典列表构造引用记录。

    @param links 包含 url/title/snippet 的字典列表
    @param source_type 引用类型
    @return 引用记录列表
    """
    citations: list[CitationRecord] = []

    for index, link in enumerate(links, start=1):
        url = normalize_url(link.get("url", ""))
        if not is_citation_source_url(url):
            continue

        citations.append(
            CitationRecord(
                url=url,
                title=(link.get("title") or "").strip(),
                snippet=(link.get("snippet") or "").strip(),
                source_type=source_type,
                position=index,
            )
        )

    return dedupe_citations(citations)


def merge_citations(*groups: list[CitationRecord]) -> list[CitationRecord]:
    """
    合并多组引用并去重。

    @param groups 多组引用列表
    @return 合并去重后的引用列表
    """
    merged: list[CitationRecord] = []
    for group in groups:
        merged.extend(group)

    return dedupe_citations(merged)


def save_json(path: Path, payload: dict | list) -> None:
    """
    保存 JSON 文件。

    @param path 目标路径
    @param payload 可序列化对象
    """
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
