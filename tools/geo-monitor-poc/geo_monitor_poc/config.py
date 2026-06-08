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
        screenshot_scroll_selectors=[
            ".ds-scroll-area",
            "main",
            "[class*='scroll']",
        ],
    ),
    PlatformId.DOUBAO: PlatformSelectors(
        selector_version="2026-06-03-poc-v5-doubao-fast",
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
            ".v_list_row:has([data-foundation-type='receive-message-action-bar'])",
            ".v_list_row:has([data-foundation-type='receive-message-action-bar']) .markdown-body",
            "[data-testid*='receive_message']",
            ".message-item .markdown-body",
        ],
        citation_link_selectors=[
            "[data-plugin-identifier*='search_query_result_block'] a[href^='http']",
            ".semi-portal a[href^='http']",
            "[class*='search'] a[href^='http']",
            "a[href^='http']",
        ],
        citation_card_selectors=[
            "[data-plugin-identifier*='search_query_result_block']",
            "[class*='reference']",
            "[class*='source-card']",
            "[class*='search-source']",
            "[class*='footnote']",
        ],
        logged_in_markers=[
            "#input-engine-container textarea.semi-input-textarea",
        ],
        login_markers=[
            "text=登录以解锁更多功能",
            "text=抖音一键登录",
            "text=立即登录",
            "text=请登录",
        ],
        captcha_markers=[
            "#captcha_container",
            "iframe[src*='verifycenter/captcha']",
            "iframe[src*='rc-verifycenter']",
            "iframe[src*='bytedance.com/verify']",
            "[id*='captcha']",
            "text=验证码",
            "text=安全验证",
            "text=滑动验证",
            "text=请完成验证",
            "text=请拖动滑块",
        ],
        answer_done_markers=[
            "button[aria-label*='复制']",
            "button[data-testid*='copy']",
        ],
        guest_mode_allowed=False,
        page_ready_selector="#input-engine-container textarea.semi-input-textarea",
        screenshot_scroll_selectors=[
            "#chat-route-layout",
            ".conversation-container",
            ".flow-chat-scroll",
            "[class*='chat-scroll']",
            ".v_list_container",
        ],
        web_search_toggle_selectors=[],
        onboarding_dismiss_selectors=[],
    ),
    PlatformId.YUANBAO: PlatformSelectors(
        selector_version="2026-06-04-poc-v5-yuanbao-login-wall",
        chat_url="https://yuanbao.tencent.com/chat",
        input_selectors=[
            ".chat-input-editor [contenteditable='true']",
            ".chat-input-editor",
            "textarea",
            "[contenteditable='true']",
            "[role='textbox']",
        ],
        send_selectors=[
            ".style__send-btn___RwTm5",
            "button[type='submit']",
            "button[aria-label*='发送']",
        ],
        answer_container_selectors=[
            ".agent-chat__list__item--ai .agent-chat__speech-card__text",
            ".agent-chat__bubble--ai .agent-chat__speech-card__text",
            ".agent-chat__bubble--ai .agent-chat__bubble__content",
            ".agent-chat__list__item--ai .agent-chat__bubble__content",
        ],
        citation_link_selectors=[
            "a[href^='http']",
            ".ToolbarSearchGuid_source__XMsID a",
            "[class*='reference'] a",
            "[class*='source'] a",
            "[class*='citation'] a",
            "[data-toolbar-type='citation'] a",
        ],
        citation_card_selectors=[
            ".ToolbarSearchGuid_source__XMsID",
            "[class*='reference']",
            "[class*='source']",
            "[class*='citation']",
            "#search-guide-tool",
        ],
        logged_in_markers=[
            ".yb-user-avatar",
            "[class*='user-avatar']",
            "[class*='UserAvatar']",
        ],
        login_markers=[
            "text=未登录",
            "text=请使用微信扫描二维码登录",
            "text=微信登录",
            "text=QQ登录",
        ],
        captcha_markers=[
            "text=验证码",
            "text=安全验证",
        ],
        answer_done_markers=[
            "button[aria-label*='复制']",
            ".agent-chat__conv--ai__toolbar:not(.agent-chat__conv--ai__toolbar--loading)",
        ],
        page_ready_selector=".chat-input-editor",
        screenshot_scroll_selectors=[
            ".agent-chat__list",
            ".agent-chat__content",
            ".yb-layout__content",
        ],
        web_search_toggle_selectors=[
            "text=联网搜索",
            '[dt-button-id="deep_think"]',
            ".ThinkSelector_iconContainer__5HMzp",
        ],
        onboarding_dismiss_selectors=[
            ".auto-search-guide-popup__button",
            "text=我知道了",
        ],
    ),
}

DEFAULT_PROBE_TIMEOUT_MS = 120_000
DEFAULT_ANSWER_WAIT_MS = 45_000
DEFAULT_POST_ANSWER_WAIT_MS = 3_000
