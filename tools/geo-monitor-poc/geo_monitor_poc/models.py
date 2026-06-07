from __future__ import annotations

from dataclasses import dataclass, field
from enum import Enum
from typing import Any


class PlatformId(str, Enum):
    """支持的 AI 平台标识。"""

    DOUBAO = "doubao"
    DEEPSEEK = "deepseek"
    YUANBAO = "yuanbao"


class LoginStatus(str, Enum):
    """登录态检查结果。"""

    LOGGED_IN = "logged_in"
    NOT_LOGGED_IN = "not_logged_in"
    UNKNOWN = "unknown"
    CAPTCHA = "captcha_required"
    BLOCKED = "blocked"


class ProbeStatus(str, Enum):
    """单次探测结果状态。"""

    SUCCESS = "success"
    PARTIAL = "partial"
    FAILED = "failed"
    NEEDS_LOGIN = "needs_login"
    CAPTCHA = "captcha_required"
    SELECTOR_MISS = "selector_miss"


@dataclass(slots=True)
class PlatformSelectors:
    """平台 DOM 选择器集合；页面改版时可单独更新 selector_version。"""

    selector_version: str
    chat_url: str
    input_selectors: list[str]
    send_selectors: list[str]
    answer_container_selectors: list[str]
    citation_link_selectors: list[str]
    citation_card_selectors: list[str]
    logged_in_markers: list[str]
    login_markers: list[str]
    captcha_markers: list[str]
    answer_done_markers: list[str]
    guest_mode_allowed: bool = False
    page_ready_selector: str = ""
    screenshot_scroll_selectors: list[str] = field(default_factory=list)
    web_search_toggle_selectors: list[str] = field(default_factory=list)
    onboarding_dismiss_selectors: list[str] = field(default_factory=list)


@dataclass(slots=True)
class AccountConfig:
    """单个平台账号与浏览器 profile 配置。"""

    id: str
    platform: PlatformId
    label: str
    profile_dir: str
    proxy: str = ""
    locale: str = "zh-CN"
    timezone_id: str = "Asia/Shanghai"
    enabled: bool = True


@dataclass(slots=True)
class CitationRecord:
    """从回答中抽取的单条引用/来源。"""

    url: str
    title: str = ""
    snippet: str = ""
    source_type: str = "link"
    position: int = 0


@dataclass(slots=True)
class ProbeEvidence:
    """单次探测保留的证据路径。"""

    screenshot_path: str = ""
    html_path: str = ""
    markdown_path: str = ""
    raw_text_path: str = ""


@dataclass(slots=True)
class ProbeResult:
    """单平台单问题的探测结果。"""

    platform: PlatformId
    account_id: str
    prompt_id: str
    prompt_text: str
    status: ProbeStatus
    login_status: LoginStatus
    answer_text: str = ""
    citations: list[CitationRecord] = field(default_factory=list)
    evidence: ProbeEvidence = field(default_factory=ProbeEvidence)
    error_message: str = ""
    duration_ms: int = 0
    meta: dict[str, Any] = field(default_factory=dict)

    def to_dict(self) -> dict[str, Any]:
        """
        转为可 JSON 序列化的字典。

        @return array<string, mixed>
        """
        return {
            "platform": self.platform.value,
            "account_id": self.account_id,
            "prompt_id": self.prompt_id,
            "prompt_text": self.prompt_text,
            "status": self.status.value,
            "login_status": self.login_status.value,
            "answer_text": self.answer_text,
            "citations": [
                {
                    "url": item.url,
                    "title": item.title,
                    "snippet": item.snippet,
                    "source_type": item.source_type,
                    "position": item.position,
                }
                for item in self.citations
            ],
            "evidence": {
                "screenshot_path": self.evidence.screenshot_path,
                "html_path": self.evidence.html_path,
                "markdown_path": self.evidence.markdown_path,
                "raw_text_path": self.evidence.raw_text_path,
            },
            "error_message": self.error_message,
            "duration_ms": self.duration_ms,
            "meta": self.meta,
        }
