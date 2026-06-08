from __future__ import annotations

import time
from typing import Any

from geo_monitor_poc.adapters.base import PlatformAdapter
from geo_monitor_poc.config import DEFAULT_ANSWER_WAIT_MS, DEFAULT_POST_ANSWER_WAIT_MS
from geo_monitor_poc.models import LoginStatus, PlatformId, ProbeResult, ProbeStatus


class YuanbaoAdapter(PlatformAdapter):
    """腾讯元宝平台适配器。"""

    platform = PlatformId.YUANBAO

    MIN_ANSWER_CHARS = 80
    LOGIN_WALL_MARKERS = (
        "text=未登录",
        "text=请使用微信扫描二维码登录",
        "text=扫码默认已阅读并同意",
        "text=微信登录",
    )
    GUEST_LANDING_ANSWER_MARKERS = (
        "未登录",
        "请使用微信扫描二维码登录",
        "你身边的智能助手",
        "你可以这样问",
        "扫码默认已阅读并同意",
    )

    def prepare_page(self, page: Any) -> None:
        """
        等待输入区就绪，并尝试开启联网搜索、关闭新手引导。

        @param page Playwright Page
        """
        super().prepare_page(page)
        self.dismiss_onboarding(page)
        self.enable_online_search(page)

    def dismiss_onboarding(self, page: Any) -> None:
        """
        关闭「智能联网」等新手引导弹层，避免遮挡输入区。

        @param page Playwright Page
        """
        for selector in self.selectors.onboarding_dismiss_selectors:
            locator = page.locator(selector)
            count = min(locator.count(), 3)
            for index in range(count):
                candidate = locator.nth(index)
                try:
                    if candidate.is_visible():
                        candidate.click(timeout=3_000)
                        page.wait_for_timeout(500)
                except Exception:  # noqa: BLE001
                    continue

    def enable_online_search(self, page: Any) -> None:
        """
        尽量开启元宝联网搜索：显式开关、深度思考、工具菜单内选项。

        @param page Playwright Page
        """
        if self._click_visible_text(page, "联网搜索"):
            page.wait_for_timeout(500)
            return

        switch = page.locator("[role='switch']").filter(has_text="联网")
        if switch.count() > 0:
            self._ensure_switch_on(page, switch.first)
            page.wait_for_timeout(500)
            return

        for selector in self.selectors.web_search_toggle_selectors:
            if selector.startswith("text="):
                continue

            locator = page.locator(selector)
            if locator.count() == 0:
                continue

            candidate = locator.first
            try:
                if not candidate.is_visible():
                    continue
            except Exception:  # noqa: BLE001
                continue

            if selector == '[dt-button-id="deep_think"]':
                if not self._is_toggle_active(candidate):
                    candidate.click(timeout=5_000)
                    page.wait_for_timeout(600)
                continue

            candidate.click(timeout=5_000)
            page.wait_for_timeout(600)

        self._enable_online_search_via_tools_menu(page)

    def _enable_online_search_via_tools_menu(self, page: Any) -> None:
        """
        打开输入栏「工具」下拉，尝试勾选联网相关能力。

        @param page Playwright Page
        """
        tools = page.locator(".ybc-atomSelect-tools")
        if tools.count() == 0:
            return

        try:
            if not tools.first.is_visible():
                return
        except Exception:  # noqa: BLE001
            return

        tools.first.click(timeout=5_000)
        page.wait_for_timeout(700)

        for label in ("联网搜索", "联网", "网页搜索", "搜索"):
            if self._click_visible_text(page, label):
                page.wait_for_timeout(400)
                return

        page.keyboard.press("Escape")

    def _click_visible_text(self, page: Any, text: str) -> bool:
        """
        点击页面上第一个可见的包含指定文案的元素。

        @param page Playwright Page
        @param text 目标文案
        @return 是否点击成功
        """
        locator = page.get_by_text(text, exact=False)
        count = min(locator.count(), 8)
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

    def _ensure_switch_on(self, page: Any, switch: Any) -> None:
        """
        若开关处于关闭态则点击打开。

        @param page Playwright Page
        @param switch 开关 Locator
        """
        try:
            pressed = switch.get_attribute("aria-checked")
            if pressed == "true":
                return
        except Exception:  # noqa: BLE001
            pass

        if not self._is_toggle_active(switch):
            switch.click(timeout=3_000)

    def _is_toggle_active(self, element: Any) -> bool:
        """
        根据 class / aria 判断工具栏按钮是否已激活。

        @param element Locator
        @return 是否处于激活态
        """
        try:
            return bool(
                element.evaluate(
                    """el => {
                        const cls = (el.className || '').toString().toLowerCase();
                        if (cls.includes('active') || cls.includes('selected') || cls.includes('checked')) {
                            return true;
                        }
                        if (el.getAttribute('aria-pressed') === 'true') {
                            return true;
                        }
                        if (el.getAttribute('aria-checked') === 'true') {
                            return true;
                        }
                        const style = window.getComputedStyle(el);
                        if (style && parseFloat(style.opacity || '1') < 0.95) {
                            return false;
                        }
                        return false;
                    }""",
                ),
            )
        except Exception:  # noqa: BLE001
            return False

    def detect_login_status(self, page: Any) -> LoginStatus:
        """
        检测元宝登录态；访客首页也有输入框，不能仅凭输入区判断已登录。

        @param page Playwright Page
        @return 登录态枚举
        """
        if self.has_captcha(page):
            return LoginStatus.CAPTCHA

        if self._has_visible_login_wall(page):
            return LoginStatus.NOT_LOGGED_IN

        if not self.has_input_ready(page):
            return LoginStatus.NOT_LOGGED_IN

        return LoginStatus.LOGGED_IN

    def _has_visible_login_wall(self, page: Any) -> bool:
        """
        判断页面是否展示登录墙/扫码登录层。

        @param page Playwright Page
        @return 是否存在可见登录墙
        """
        for marker in self.LOGIN_WALL_MARKERS:
            locator = page.locator(marker)
            count = min(locator.count(), 3)
            for index in range(count):
                candidate = locator.nth(index)
                try:
                    if candidate.is_visible():
                        return True
                except Exception:  # noqa: BLE001
                    continue

        return False

    def is_guest_landing_answer(self, answer_text: str) -> bool:
        """
        判断抽取文本是否为未登录首页/登录引导，而非真实 AI 回答。

        @param answer_text 页面抽取文本
        @return 是否为访客落地页文案
        """
        normalized = answer_text.strip()
        if normalized == "":
            return False

        hits = sum(1 for marker in self.GUEST_LANDING_ANSWER_MARKERS if marker in normalized)
        if hits >= 2:
            return True

        return "未登录" in normalized and "微信登录" in normalized

    def build_probe_result(self, **kwargs: Any) -> ProbeResult:
        """
        构造探测结果；若回答实为未登录首页文案则降级为 needs_login。

        @param kwargs 与基类 build_probe_result 相同
        @return 探测结果
        """
        result = super().build_probe_result(**kwargs)
        if result.status in {ProbeStatus.NEEDS_LOGIN, ProbeStatus.CAPTCHA}:
            return result

        if not self.is_guest_landing_answer(result.answer_text):
            return result

        return ProbeResult(
            platform=result.platform,
            account_id=result.account_id,
            prompt_id=result.prompt_id,
            prompt_text=result.prompt_text,
            status=ProbeStatus.NEEDS_LOGIN,
            login_status=LoginStatus.NOT_LOGGED_IN,
            answer_text=result.answer_text,
            citations=[],
            evidence=result.evidence,
            error_message="检测到未登录首页/登录引导，请先执行 login 或维护 profile",
            duration_ms=result.duration_ms,
            meta=result.meta,
        )

    def is_answer_loading(self, page: Any) -> bool:
        """
        判断 AI 回复是否仍在生成中。

        @param page Playwright Page
        @return 是否 loading
        """
        loading = page.locator(".agent-chat__conv--ai__toolbar--loading")
        try:
            return loading.count() > 0 and loading.first.is_visible()
        except Exception:  # noqa: BLE001
            return loading.count() > 0

    def wait_for_answer(self, page: Any) -> None:
        """
        等待联网回答生成完成；loading 期间不因侧栏静态文案提前结束。

        @param page Playwright Page
        """
        deadline = time.time() + (DEFAULT_ANSWER_WAIT_MS / 1000)
        last_text = ""

        while time.time() < deadline:
            if self.is_answer_loading(page):
                last_text = ""
                page.wait_for_timeout(1_500)
                continue

            current_text = self.extract_answer_text(page)
            cleaned = current_text.strip()
            if len(cleaned) >= self.MIN_ANSWER_CHARS and cleaned == last_text.strip():
                return

            last_text = current_text
            page.wait_for_timeout(1_500)

        page.wait_for_timeout(DEFAULT_POST_ANSWER_WAIT_MS)

    def build_probe_action(self, prompt_text: str):
        """
        构造元宝提问自动化回调。

        @param prompt_text 待发送问题
        @return Playwright page 回调
        """
        prompt = prompt_text

        def _action(page):
            page.wait_for_timeout(2_000)
            if not self.fill_prompt(page, prompt):
                raise RuntimeError("未找到元宝输入框，可能需要更新 selector 或重新登录")
            self.click_send(page)
            self.wait_for_answer(page)

        return _action
