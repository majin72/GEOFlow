from __future__ import annotations

import time
from typing import Any

from geo_monitor_poc.adapters.base import PlatformAdapter
from geo_monitor_poc.citations import (
    extract_citations_from_links,
    is_citation_source_url,
    merge_citations,
    normalize_url,
)
from geo_monitor_poc.config import DEFAULT_ANSWER_WAIT_MS, DEFAULT_POST_ANSWER_WAIT_MS
from geo_monitor_poc.models import CitationRecord, LoginStatus, PlatformId


class DoubaoAdapter(PlatformAdapter):
    """豆包平台适配器。"""

    platform = PlatformId.DOUBAO

    PREFERRED_CHAT_MODE = "快速"
    MIN_ANSWER_CHARS = 120
    LOGIN_WALL_MARKERS = (
        "text=登录以解锁更多功能",
        "text=抖音一键登录",
        "text=请登录",
        "text=立即登录",
    )

    def prepare_page(self, page: Any) -> None:
        """
        等待输入区就绪，并确保使用「快速」对话模式（不切换到思考/专家）。

        @param page Playwright Page
        """
        super().prepare_page(page)
        self.ensure_fast_mode(page)

    def get_current_mode_label(self, page: Any) -> str:
        """
        读取输入栏模式切换按钮当前文案（如「快速」「思考」）。

        @param page Playwright Page
        @return 模式标签文本
        """
        locator = page.locator('[data-valid-btn="mode-select-action-btn"] button').first
        try:
            if locator.count() == 0:
                return ""
            return (locator.inner_text(timeout=2_000) or "").strip()
        except Exception:  # noqa: BLE001
            return ""

    def detect_login_status(self, page: Any) -> LoginStatus:
        """
        检测豆包登录态；访客约 5 轮对话后会弹出「登录以解锁更多功能」。

        侧栏常驻「登录」链接不能作为未登录依据，只识别登录墙/一键登录弹层。

        @param page Playwright Page
        @return 登录态枚举
        """
        if self.has_captcha(page):
            return LoginStatus.CAPTCHA

        for marker in self.LOGIN_WALL_MARKERS:
            locator = page.locator(marker)
            count = min(locator.count(), 3)
            for index in range(count):
                candidate = locator.nth(index)
                try:
                    if candidate.is_visible():
                        return LoginStatus.NOT_LOGGED_IN
                except Exception:  # noqa: BLE001
                    continue

        if not self.has_input_ready(page):
            return LoginStatus.NOT_LOGGED_IN

        return LoginStatus.LOGGED_IN

    def ensure_fast_mode(self, page: Any) -> None:
        """
        保持豆包为「快速」模式；若当前为思考/专家则切回快速。

        @param page Playwright Page
        """
        current = self.get_current_mode_label(page)
        if self.PREFERRED_CHAT_MODE in current:
            return

        trigger = page.locator('[data-valid-btn="mode-select-action-btn"]').first
        try:
            if trigger.count() == 0 or not trigger.is_visible():
                return
        except Exception:  # noqa: BLE001
            return

        trigger.click(timeout=5_000)
        page.wait_for_timeout(600)

        if self._click_menu_option(page, self.PREFERRED_CHAT_MODE):
            page.wait_for_timeout(400)

        page.keyboard.press("Escape")
        page.wait_for_timeout(300)

    def _click_menu_option(self, page: Any, text: str) -> bool:
        """
        在 Radix 下拉/菜单中点击指定选项。

        @param page Playwright Page
        @param text 菜单项文案
        @return 是否点击成功
        """
        for selector in (
            f'[role="menuitem"]:has-text("{text}")',
            f'[role="option"]:has-text("{text}")',
            f'[data-radix-collection-item]:has-text("{text}")',
        ):
            locator = page.locator(selector)
            if locator.count() == 0:
                continue
            try:
                candidate = locator.first
                if candidate.is_visible():
                    candidate.click(timeout=3_000)
                    return True
            except Exception:  # noqa: BLE001
                continue

        locator = page.get_by_text(text, exact=True)
        count = min(locator.count(), 10)
        for index in range(count):
            candidate = locator.nth(index)
            try:
                if not candidate.is_visible():
                    continue
                candidate.click(timeout=3_000)
                return True
            except Exception:  # noqa: BLE001
                continue

        return False

    def has_copy_button(self, page: Any) -> bool:
        """
        判断 AI 回复是否已出现复制按钮（生成完成的重要信号）。

        @param page Playwright Page
        @return 是否存在复制按钮
        """
        for marker in self.selectors.answer_done_markers:
            if page.locator(marker).count() > 0:
                return True

        return False

    def is_answer_generating(self, page: Any) -> bool:
        """
        判断豆包是否仍在流式输出中（快速模式一般无「跳过」梳理阶段）。

        @param page Playwright Page
        @return 是否仍在生成
        """
        if self.has_copy_button(page):
            return False

        answer_text = self.extract_answer_text(page).strip()
        if answer_text == "":
            return True

        return len(answer_text) < self.MIN_ANSWER_CHARS

    def is_answer_complete(self, page: Any, answer_text: str) -> bool:
        """
        判断当前抽取结果是否可视为完整回答。

        @param page Playwright Page
        @param answer_text 已抽取文本
        @return 是否完成
        """
        cleaned = answer_text.strip()
        if len(cleaned) < self.MIN_ANSWER_CHARS:
            return False

        if self.is_answer_generating(page):
            return False

        if self.has_copy_button(page):
            return True

        return len(cleaned) >= self.MIN_ANSWER_CHARS

    def wait_for_answer(self, page: Any) -> None:
        """
        等待豆包「快速」模式回答生成完成，避免侧栏静态文案导致过早结束。

        @param page Playwright Page
        """
        deadline = time.time() + (DEFAULT_ANSWER_WAIT_MS / 1000)
        last_text = ""
        stable_rounds = 0

        while time.time() < deadline:
            self.wait_for_human_intervention(
                page,
                interactive=self.interactive,
                stage="等待回答中",
            )

            if self.is_answer_generating(page):
                last_text = ""
                stable_rounds = 0
                page.wait_for_timeout(1_500)
                continue

            current_text = self.extract_answer_text(page)
            if self.is_answer_complete(page, current_text) and current_text.strip() == last_text.strip():
                stable_rounds += 1
                if stable_rounds >= 2:
                    return
            else:
                stable_rounds = 0

            last_text = current_text
            page.wait_for_timeout(1_500)

        page.wait_for_timeout(DEFAULT_POST_ANSWER_WAIT_MS)

    def extract_answer_text(self, page: Any) -> str:
        """
        仅抽取最新 AI 回复气泡文本，避免侧栏与输入区技能菜单。

        @param page Playwright Page
        @return 回答文本
        """
        receive_row = page.locator(
            ".v_list_row:has([data-foundation-type='receive-message-action-bar'])",
        )
        try:
            if receive_row.count() > 0:
                text = receive_row.last.inner_text(timeout=3_000).strip()
                if text:
                    return text
        except Exception:  # noqa: BLE001
            pass

        return super().extract_answer_text(page)

    def expand_search_sources_panel(self, page: Any) -> bool:
        """
        点击「参考 N 篇资料」展开搜索来源面板（链接默认不在静态 HTML 里）。

        @param page Playwright Page
        @return 是否成功点击展开入口
        """
        block = page.locator('[data-plugin-identifier*="search_query_result_block"]')
        if block.count() == 0:
            return False

        trigger = block.locator(".cursor-pointer").first
        try:
            if not trigger.is_visible():
                return False
            trigger.click(timeout=5_000)
            page.wait_for_timeout(2_000)
            return True
        except Exception:  # noqa: BLE001
            return False

    def collect_external_link_dicts(self, page: Any) -> list[dict[str, str]]:
        """
        从页面可见区域收集外部引用链接（过滤豆包站内与 CDN）。

        @param page Playwright Page
        @return 链接字典列表
        """
        links: list[dict[str, str]] = []
        locator = page.locator("a[href^='http']")
        count = min(locator.count(), 60)

        for index in range(count):
            element = locator.nth(index)
            try:
                if not element.is_visible():
                    continue
            except Exception:  # noqa: BLE001
                continue

            href = normalize_url((element.get_attribute("href") or "").strip())
            if not is_citation_source_url(href):
                continue

            title = (element.inner_text(timeout=1_000) or "").strip()
            links.append({"url": href, "title": title, "snippet": title})

        return links

    def extract_citations(self, page: Any, answer_text: str) -> list[CitationRecord]:
        """
        抽取豆包引用：先展开「参考 N 篇资料」，再合并 DOM 与正文 URL。

        @param page Playwright Page
        @param answer_text 回答文本
        @return 引用记录列表
        """
        if "篇资料" in answer_text or "参考" in self.extract_answer_text(page):
            self.expand_search_sources_panel(page)

        panel_links = extract_citations_from_links(
            self.collect_external_link_dicts(page),
            source_type="doubao_search_panel",
        )

        return merge_citations(panel_links, super().extract_citations(page, answer_text))

    def build_probe_action(self, prompt_text: str):
        """
        构造豆包提问自动化回调。

        @param prompt_text 待发送问题
        @return Playwright page 回调
        """
        prompt = prompt_text

        def _action(page):
            page.wait_for_timeout(2_000)
            if not self.fill_prompt(page, prompt):
                raise RuntimeError("未找到豆包输入框，可能需要更新 selector 或重新登录")
            self.click_send(page)
            self.wait_for_answer(page)

        return _action
