from __future__ import annotations

from geo_monitor_poc.models import PlatformId, PlatformSelectors

PLATFORM_SELECTORS: dict[PlatformId, PlatformSelectors] = {
    PlatformId.DEEPSEEK: PlatformSelectors(
        selector_version="2026-06-03-poc-v1",
        chat_url="https://chat.deepseek.com/",
        input_selectors=[
            "textarea",
            "[contenteditable='true']",
            "[role='textbox']",
        ],
        send_selectors=[
            "button[type='submit']",
            "button[aria-label*='Send']",
            "button[aria-label*='发送']",
        ],
        answer_container_selectors=[
            "[data-testid*='message']",
            ".message",
            ".markdown-body",
            "article",
        ],
        citation_link_selectors=[
            "a[href^='http']",
            "[data-testid*='reference'] a",
            ".reference a",
            ".source a",
        ],
        citation_card_selectors=[
            "[data-testid*='reference']",
            ".reference",
            ".source-card",
        ],
        logged_in_markers=[
            "textarea",
            "[contenteditable='true']",
            "[role='textbox']",
        ],
        login_markers=[
            "text=登录",
            "text=Sign in",
            "text=Log in",
        ],
        captcha_markers=[
            "text=验证码",
            "text=安全验证",
            "text=Verify",
        ],
        answer_done_markers=[
            "button[aria-label*='Copy']",
            "button[aria-label*='复制']",
        ],
    ),
    PlatformId.DOUBAO: PlatformSelectors(
        selector_version="2026-06-03-poc-v3-doubao-semi",
        chat_url="https://www.doubao.com/chat/",
        input_selectors=[
            "#input-engine-container textarea.semi-input-textarea",
            "textarea.semi-input-textarea[placeholder='发消息...']",
            "textarea[placeholder='发消息...']",
            "textarea.semi-input-textarea",
        ],
        send_selectors=[
            "#input-engine-container [data-state='active']",
            "button[aria-label*='发送']",
        ],
        answer_container_selectors=[
            "[data-testid*='receive_message']",
            "[data-testid*='message']",
            ".message-item",
            ".markdown-body",
            "#root article",
        ],
        citation_link_selectors=[
            "a[href^='http']",
            "[class*='reference'] a",
            "[class*='source'] a",
            "[class*='citation'] a",
            "[class*='search'] a",
        ],
        citation_card_selectors=[
            "[class*='reference']",
            "[class*='source-card']",
            "[class*='search-source']",
            "[class*='footnote']",
        ],
        logged_in_markers=[
            "#input-engine-container textarea.semi-input-textarea",
        ],
        login_markers=[
            "text=登录",
            "text=立即登录",
        ],
        captcha_markers=[
            "#captcha_container",
            "iframe[src*='verifycenter/captcha']",
            "iframe[src*='bytedance.com/verify']",
            "text=验证码",
            "text=安全验证",
            "text=滑动验证",
            "text=请完成验证",
        ],
        answer_done_markers=[
            "button[aria-label*='复制']",
            "button[data-testid*='copy']",
        ],
        guest_mode_allowed=True,
        page_ready_selector="#input-engine-container textarea.semi-input-textarea",
    ),
    PlatformId.YUANBAO: PlatformSelectors(
        selector_version="2026-06-03-poc-v1",
        chat_url="https://yuanbao.tencent.com/chat",
        input_selectors=[
            "textarea",
            "[contenteditable='true']",
            "[role='textbox']",
        ],
        send_selectors=[
            "button[type='submit']",
            "button[aria-label*='发送']",
        ],
        answer_container_selectors=[
            "[data-testid*='message']",
            ".message",
            ".markdown-body",
            "article",
        ],
        citation_link_selectors=[
            "a[href^='http']",
            "[class*='reference'] a",
            "[class*='source'] a",
            "[class*='citation'] a",
        ],
        citation_card_selectors=[
            "[class*='reference']",
            "[class*='source']",
            "[class*='citation']",
        ],
        logged_in_markers=[
            "textarea",
            "[contenteditable='true']",
            "[role='textbox']",
        ],
        login_markers=[
            "text=登录",
            "text=微信登录",
            "text=QQ登录",
        ],
        captcha_markers=[
            "text=验证码",
            "text=安全验证",
        ],
        answer_done_markers=[
            "button[aria-label*='复制']",
        ],
    ),
}

DEFAULT_PROBE_TIMEOUT_MS = 120_000
DEFAULT_ANSWER_WAIT_MS = 45_000
DEFAULT_POST_ANSWER_WAIT_MS = 3_000
