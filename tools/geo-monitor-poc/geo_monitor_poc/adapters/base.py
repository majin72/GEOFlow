from __future__ import annotations

import time
from abc import ABC, abstractmethod
from pathlib import Path
from typing import Any

from geo_monitor_poc.citations import (
    extract_citations_from_links,
    extract_urls_from_text,
    merge_citations,
)
from geo_monitor_poc.config import DEFAULT_ANSWER_WAIT_MS, DEFAULT_POST_ANSWER_WAIT_MS, PLATFORM_SELECTORS
from geo_monitor_poc.exceptions import CaptchaRequiredError
from geo_monitor_poc.models import (
    AccountConfig,
    CitationRecord,
    LoginStatus,
    PlatformId,
    ProbeEvidence,
    ProbeResult,
    ProbeStatus,
)
from geo_monitor_poc.long_screenshot import capture_long_screenshot
from geo_monitor_poc.utils import now_ms


class PlatformAdapter(ABC):
    """AI 平台采集适配器基类。"""

    platform: PlatformId

    def __init__(self, account: AccountConfig) -> None:
        self.account = account
        self.selectors = PLATFORM_SELECTORS[self.platform]
        self.interactive = False

    @abstractmethod
    def build_probe_action(self, prompt_text: str) -> Any:
        """
        构造 Scrapling page_action 回调。

        @param prompt_text 待发送问题
        @return Playwright page 回调函数
        """

    def find_input_locator(self, page: Any) -> Any | None:
        """
        查找第一个可见且非 aria-hidden 的输入框。

        @param page Playwright Page
        @return 输入框 Locator 或 None
        """
        for selector in self.selectors.input_selectors:
            locator = page.locator(selector)
            count = locator.count()
            for index in range(count):
                candidate = locator.nth(index)
                try:
                    if candidate.get_attribute("aria-hidden") == "true":
                        continue
                    if not candidate.is_visible():
                        continue
                except Exception:  # noqa: BLE001
                    continue

                return candidate

        return None

    def prepare_page(self, page: Any) -> None:
        """
        等待页面输入区就绪。

        @param page Playwright Page
        """
        ready_selector = self.selectors.page_ready_selector.strip()
        if ready_selector != "":
            page.locator(ready_selector).first.wait_for(state="visible", timeout=30_000)
            return

        deadline = time.time() + 30
        while time.time() < deadline:
            if self.find_input_locator(page) is not None:
                return
            page.wait_for_timeout(500)

        raise RuntimeError(f"{self.platform.value} 输入框未在超时内出现")

    def has_captcha(self, page: Any) -> bool:
        """
        检测页面是否出现验证码遮罩（含 iframe 验证码）。

        @param page Playwright Page
        @return 是否存在可见验证码
        """
        for marker in self.selectors.captcha_markers:
            locator = page.locator(marker)
            count = locator.count()
            for index in range(count):
                candidate = locator.nth(index)
                try:
                    if candidate.is_visible():
                        return True
                except Exception:  # noqa: BLE001
                    continue

        return False

    def wait_for_human_intervention(
        self,
        page: Any,
        *,
        interactive: bool,
        stage: str,
    ) -> None:
        """
        若出现验证码：交互模式暂停等人处理；无头/生产模式抛出 CaptchaRequiredError。

        @param page Playwright Page
        @param interactive 是否允许终端暂停等待人工操作
        @param stage 当前阶段描述
        @raises CaptchaRequiredError 非交互模式下检测到验证码
        """
        if not self.has_captcha(page):
            return

        if not interactive:
            raise CaptchaRequiredError(
                platform=self.platform,
                account_id=self.account.id,
                stage=stage,
            )

        print("")
        print("!" * 60)
        print(f"【需要人工处理】{self.platform.value} 出现验证码（阶段: {stage}）")
        print("请在已打开的浏览器窗口中完成滑动/点选验证。")
        print("完成后回到本终端，按 Enter 继续采集。")
        print("!" * 60)
        print("")

        while self.has_captcha(page):
            input("验证码完成后按 Enter 继续 >>> ")
            page.wait_for_timeout(1_500)

        page.wait_for_timeout(1_000)
        print(f"[{self.platform.value}] 验证码已通过，继续执行。")
        print("")

    def detect_login_status(self, page: Any) -> LoginStatus:
        """
        检测当前页面登录态。

        @param page Playwright Page
        @return 登录态枚举
        """
        if self.has_captcha(page):
            return LoginStatus.CAPTCHA

        if self.selectors.guest_mode_allowed and self.has_input_ready(page):
            return LoginStatus.LOGGED_IN

        for marker in self.selectors.logged_in_markers:
            if page.locator(marker).count() > 0:
                return LoginStatus.LOGGED_IN

        for marker in self.selectors.login_markers:
            if page.locator(marker).count() > 0:
                return LoginStatus.NOT_LOGGED_IN

        return LoginStatus.UNKNOWN

    def has_input_ready(self, page: Any) -> bool:
        """
        判断聊天输入框是否可用（访客模式也可用）。

        @param page Playwright Page
        @return 输入框是否可用
        """
        return self.find_input_locator(page) is not None

    def fill_prompt(self, page: Any, prompt_text: str) -> bool:
        """
        向输入框填入问题。

        @param page Playwright Page
        @param prompt_text 问题文本
        @return 是否成功找到输入框
        """
        locator = self.find_input_locator(page)
        if locator is None:
            return False

        locator.scroll_into_view_if_needed(timeout=5_000)
        locator.click(timeout=5_000)
        tag_name = locator.evaluate("el => el.tagName.toLowerCase()")

        if tag_name == "textarea":
            locator.fill(prompt_text)
        else:
            page.keyboard.press("Meta+A")
            page.keyboard.press("Control+A")
            page.keyboard.type(prompt_text, delay=15)

        return True

    def click_send(self, page: Any) -> bool:
        """
        点击发送按钮；若找不到按钮则尝试 Enter。

        @param page Playwright Page
        @return 是否触发发送
        """
        input_locator = self.find_input_locator(page)
        if input_locator is not None:
            input_locator.press("Enter")
            return True

        for selector in self.selectors.send_selectors:
            locator = page.locator(selector)
            count = locator.count()
            for index in range(count):
                candidate = locator.nth(index)
                try:
                    if not candidate.is_visible():
                        continue
                except Exception:  # noqa: BLE001
                    continue

                if candidate.is_enabled():
                    candidate.click(timeout=5_000)
                    return True

        page.keyboard.press("Enter")
        return True

    def wait_for_answer(self, page: Any) -> None:
        """
        等待回答生成完成。

        @param page Playwright Page
        """
        deadline = time.time() + (DEFAULT_ANSWER_WAIT_MS / 1000)
        last_text = ""

        while time.time() < deadline:
            self.wait_for_human_intervention(
                page,
                interactive=self.interactive,
                stage="等待回答中",
            )

            current_text = self.extract_answer_text(page)
            cleaned = current_text.strip()
            if cleaned != "" and cleaned == last_text.strip():
                has_done_marker = any(
                    page.locator(marker).count() > 0 for marker in self.selectors.answer_done_markers
                )
                if has_done_marker and len(cleaned) >= 40:
                    return
                if len(cleaned) >= 120:
                    return

            last_text = current_text
            page.wait_for_timeout(1_500)

        page.wait_for_timeout(DEFAULT_POST_ANSWER_WAIT_MS)

    def extract_answer_text(self, page: Any) -> str:
        """
        从页面抽取最新回答文本。

        @param page Playwright Page
        @return 回答文本
        """
        for selector in self.selectors.answer_container_selectors:
            locator = page.locator(selector)
            count = locator.count()
            if count == 0:
                continue

            texts: list[str] = []
            for index in range(max(0, count - 3), count):
                text = locator.nth(index).inner_text(timeout=2_000)
                cleaned = text.strip()
                if cleaned:
                    texts.append(cleaned)

            if texts:
                return texts[-1]

        return page.locator("body").inner_text(timeout=5_000)

    def extract_citation_links(self, page: Any) -> list[CitationRecord]:
        """
        从页面 DOM 抽取引用链接。

        @param page Playwright Page
        @return 引用记录列表
        """
        links: list[dict[str, str]] = []

        for selector in self.selectors.citation_link_selectors:
            locator = page.locator(selector)
            count = min(locator.count(), 30)
            for index in range(count):
                element = locator.nth(index)
                href = (element.get_attribute("href") or "").strip()
                if href == "":
                    continue

                title = (element.inner_text(timeout=1_000) or "").strip()
                links.append({"url": href, "title": title, "snippet": ""})

        for selector in self.selectors.citation_card_selectors:
            locator = page.locator(selector)
            count = min(locator.count(), 20)
            for index in range(count):
                card = locator.nth(index)
                href = ""
                anchor = card.locator("a[href^='http']").first
                if anchor.count() > 0:
                    href = (anchor.get_attribute("href") or "").strip()

                snippet = (card.inner_text(timeout=1_000) or "").strip()
                if href != "":
                    links.append({"url": href, "title": snippet[:120], "snippet": snippet})

        return extract_citations_from_links(links, source_type="dom_link")

    def extract_citations(self, page: Any, answer_text: str) -> list[CitationRecord]:
        """
        合并 DOM 引用与文本 URL 引用。

        @param page Playwright Page
        @param answer_text 回答文本
        @return 引用记录列表
        """
        dom_links = self.extract_citation_links(page)
        text_links = extract_citations_from_links(
            [{"url": url, "title": "", "snippet": ""} for url in extract_urls_from_text(answer_text)],
            source_type="text_url",
        )

        return merge_citations(dom_links, text_links)

    def save_evidence(self, page: Any, evidence_dir: Path, prompt_id: str) -> ProbeEvidence:
        """
        保存截图、HTML 与文本证据。

        @param page Playwright Page
        @param evidence_dir 证据目录
        @param prompt_id 问题 ID
        @return 证据路径对象
        """
        evidence_dir.mkdir(parents=True, exist_ok=True)
        prefix = evidence_dir / prompt_id

        screenshot_path = prefix.with_suffix(".png")
        html_path = prefix.with_suffix(".html")
        raw_text_path = prefix.with_suffix(".txt")

        capture_long_screenshot(
            page,
            screenshot_path,
            scroll_selectors=self.selectors.screenshot_scroll_selectors,
            answer_selectors=self.selectors.answer_container_selectors,
        )
        html_path.write_text(page.content(), encoding="utf-8")
        raw_text_path.write_text(self.extract_answer_text(page), encoding="utf-8")

        return ProbeEvidence(
            screenshot_path=str(screenshot_path),
            html_path=str(html_path),
            raw_text_path=str(raw_text_path),
        )

    def build_probe_result(
        self,
        *,
        prompt_id: str,
        prompt_text: str,
        page: Any,
        evidence_dir: Path,
        started_at_ms: int,
        error_message: str = "",
    ) -> ProbeResult:
        """
        基于当前页面状态构造探测结果。

        @param prompt_id 问题 ID
        @param prompt_text 问题文本
        @param page Playwright Page
        @param evidence_dir 证据目录
        @param started_at_ms 开始时间毫秒
        @param error_message 错误信息
        @return 探测结果
        """
        login_status = self.detect_login_status(page)
        answer_text = self.extract_answer_text(page)
        citations = self.extract_citations(page, answer_text)
        evidence = self.save_evidence(page, evidence_dir, prompt_id)

        if login_status == LoginStatus.NOT_LOGGED_IN:
            status = ProbeStatus.NEEDS_LOGIN
        elif login_status == LoginStatus.CAPTCHA:
            status = ProbeStatus.CAPTCHA
        elif error_message:
            status = ProbeStatus.FAILED
        elif answer_text.strip() == "":
            status = ProbeStatus.SELECTOR_MISS
        elif citations:
            status = ProbeStatus.SUCCESS
        else:
            status = ProbeStatus.PARTIAL

        return ProbeResult(
            platform=self.platform,
            account_id=self.account.id,
            prompt_id=prompt_id,
            prompt_text=prompt_text,
            status=status,
            login_status=login_status,
            answer_text=answer_text,
            citations=citations,
            evidence=evidence,
            error_message=error_message,
            duration_ms=now_ms() - started_at_ms,
            meta={
                "selector_version": self.selectors.selector_version,
                "chat_url": self.selectors.chat_url,
            },
        )
