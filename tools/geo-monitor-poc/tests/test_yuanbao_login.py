from __future__ import annotations

from geo_monitor_poc.adapters.yuanbao import YuanbaoAdapter
from geo_monitor_poc.models import AccountConfig, LoginStatus, PlatformId


class _FakeLocator:
    """轻量 Locator 替身，用于登录墙可见性测试。"""

    def __init__(self, count: int, *, visible: bool = True) -> None:
        self._count = count
        self._visible = visible

    def count(self) -> int:
        return self._count

    def nth(self, _index: int) -> _FakeLocator:
        return self

    def get_attribute(self, _name: str) -> str | None:
        return None

    def is_visible(self) -> bool:
        return self._visible


class _FakePage:
    """轻量 Page 替身，按 selector 返回不同 Locator。"""

    def __init__(
        self,
        *,
        visible_markers: set[str] | None = None,
        has_input: bool = True,
    ) -> None:
        self.visible_markers = visible_markers or set()
        self.has_input = has_input

    def locator(self, selector: str) -> _FakeLocator:
        if selector.startswith("text="):
            text = selector.removeprefix("text=")
            if text in self.visible_markers:
                return _FakeLocator(1, visible=True)
            return _FakeLocator(0)

        if selector in {
            ".chat-input-editor [contenteditable='true']",
            ".chat-input-editor",
            "textarea",
            "[contenteditable='true']",
            "[role='textbox']",
        }:
            return _FakeLocator(1 if self.has_input else 0, visible=self.has_input)

        return _FakeLocator(0)


def _adapter() -> YuanbaoAdapter:
    account = AccountConfig(
        id="yuanbao_account_01",
        platform=PlatformId.YUANBAO,
        label="元宝测试",
        profile_dir="profiles/yuanbao_account_01",
    )
    return YuanbaoAdapter(account)


def test_detect_login_status_marks_guest_home_as_not_logged_in() -> None:
    adapter = _adapter()
    page = _FakePage(
        visible_markers={"未登录", "请使用微信扫描二维码登录", "微信登录"},
        has_input=True,
    )

    assert adapter.detect_login_status(page) == LoginStatus.NOT_LOGGED_IN


def test_detect_login_status_allows_logged_in_chat_page() -> None:
    adapter = _adapter()
    page = _FakePage(visible_markers=set(), has_input=True)

    assert adapter.detect_login_status(page) == LoginStatus.LOGGED_IN


def test_is_guest_landing_answer_detects_login_prompt_copy() -> None:
    adapter = _adapter()
    answer = (
        "搜索 元宝 全部收藏 前往下载中心 未登录 Hi~ 我是元宝 你身边的智能助手，"
        "可以为你答疑解惑、尽情创作，快来点击以下任一功能体验吧～ 你可以这样问 "
        "北京租车推荐 登录 安装电脑版 内容由AI生成，仅供参考 微信登录 "
        "请使用微信扫描二维码登录 扫码默认已阅读并同意 用户服务协议 和 隐私协议"
    )

    assert adapter.is_guest_landing_answer(answer) is True


def test_is_guest_landing_answer_ignores_real_answer() -> None:
    adapter = _adapter()
    answer = (
        "北京租车推荐如下：1. 神州租车支持机场取还；2. 一嗨租车门店覆盖较广；"
        "3. 建议提前在官网比价并关注节假日库存。以上信息仅供参考。"
    )

    assert adapter.is_guest_landing_answer(answer) is False
