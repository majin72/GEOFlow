from __future__ import annotations

import io
from pathlib import Path
from typing import Any

from PIL import Image

MAX_STITCH_HEIGHT_PX = 20_000
SCROLL_SETTLE_MS = 180
PRELOAD_SCROLL_PAUSE_MS = 120


def capture_long_screenshot(
    page: Any,
    output_path: str | Path,
    *,
    scroll_selectors: list[str],
    answer_selectors: list[str] | None = None,
) -> str:
    """
    对聊天滚动区域生成长截图；找不到可滚动容器时回退到 full_page。

    @param page Playwright Page
    @param output_path 输出 PNG 路径
    @param scroll_selectors 平台聊天滚动容器选择器（优先级从高到低）
    @param answer_selectors 回答区域选择器，用于自动向上查找可滚动父节点
    @return 实际写入的截图路径
    """
    output = Path(output_path)
    output.parent.mkdir(parents=True, exist_ok=True)

    container = resolve_scroll_container(page, scroll_selectors, answer_selectors)
    if container is None:
        page.screenshot(path=str(output), full_page=True)
        return str(output)

    metrics = read_scroll_metrics(container)
    if metrics["scroll_height"] <= metrics["client_height"] + 8:
        screenshot_container_clip(page, container, output)
        return str(output)

    preload_scroll_content(page, container, metrics)
    stitch_scroll_container(page, container, metrics, output)
    return str(output)


def resolve_scroll_container(
    page: Any,
    scroll_selectors: list[str],
    answer_selectors: list[str] | None = None,
) -> Any | None:
    """
    解析用于长截图的滚动容器 Locator。

    @param page Playwright Page
    @param scroll_selectors 显式滚动容器选择器
    @param answer_selectors 回答区域选择器
    @return 可滚动容器 Locator 或 None
    """
    for selector in scroll_selectors:
        locator = page.locator(selector).first
        try:
            if locator.count() == 0 or not locator.is_visible():
                continue
        except Exception:  # noqa: BLE001
            continue

        metrics = read_scroll_metrics(locator)
        if metrics["scroll_height"] > metrics["client_height"] + 8:
            return locator

    if answer_selectors:
        for selector in answer_selectors:
            locator = page.locator(selector)
            count = locator.count()
            if count == 0:
                continue

            candidate = locator.nth(count - 1)
            try:
                if not candidate.is_visible():
                    continue
            except Exception:  # noqa: BLE001
                continue

            scrollable = candidate.evaluate(
                """el => {
                    let node = el;
                    while (node && node !== document.body) {
                        const style = window.getComputedStyle(node);
                        const overflowY = style.overflowY;
                        const canScroll = (overflowY === 'auto' || overflowY === 'scroll')
                            && node.scrollHeight > node.clientHeight + 20;
                        if (canScroll) {
                            const testId = node.getAttribute('data-geo-scroll-id');
                            if (!testId) {
                                const id = 'geo-scroll-' + Math.random().toString(36).slice(2);
                                node.setAttribute('data-geo-scroll-id', id);
                                return id;
                            }
                            return testId;
                        }
                        node = node.parentElement;
                    }
                    return '';
                }""",
            )
            if isinstance(scrollable, str) and scrollable != "":
                marked = page.locator(f"[data-geo-scroll-id='{scrollable}']").first
                if marked.count() > 0:
                    return marked

    for selector in scroll_selectors:
        locator = page.locator(selector).first
        try:
            if locator.count() > 0 and locator.is_visible():
                return locator
        except Exception:  # noqa: BLE001
            continue

    return None


def read_scroll_metrics(container: Any) -> dict[str, int]:
    """
    读取滚动容器尺寸信息。

    @param container 滚动容器 Locator
    @return scroll_height / client_height / scroll_width
    """
    metrics = container.evaluate(
        """el => ({
            scrollHeight: Math.max(0, Math.floor(el.scrollHeight || 0)),
            clientHeight: Math.max(0, Math.floor(el.clientHeight || 0)),
            scrollWidth: Math.max(0, Math.floor(el.scrollWidth || 0)),
            clientWidth: Math.max(0, Math.floor(el.clientWidth || 0)),
        })""",
    )

    if not isinstance(metrics, dict):
        return {
            "scroll_height": 0,
            "client_height": 0,
            "scroll_width": 0,
            "client_width": 0,
        }

    return {
        "scroll_height": int(metrics.get("scrollHeight", 0)),
        "client_height": int(metrics.get("clientHeight", 0)),
        "scroll_width": int(metrics.get("scrollWidth", 0)),
        "client_width": int(metrics.get("clientWidth", 0)),
    }


def preload_scroll_content(page: Any, container: Any, metrics: dict[str, int]) -> None:
    """
    先滚到底再回顶，触发懒加载内容后再拼接截图。

    @param page Playwright Page
    @param container 滚动容器 Locator
    @param metrics 滚动尺寸
    """
    step = max(1, metrics["client_height"])
    scroll_top = 0

    while scroll_top < metrics["scroll_height"]:
        container.evaluate(f"(el) => {{ el.scrollTop = {scroll_top}; }}")
        page.wait_for_timeout(PRELOAD_SCROLL_PAUSE_MS)
        scroll_top += step

    container.evaluate("(el) => { el.scrollTop = el.scrollHeight; }")
    page.wait_for_timeout(PRELOAD_SCROLL_PAUSE_MS)
    container.evaluate("(el) => { el.scrollTop = 0; }")
    page.wait_for_timeout(SCROLL_SETTLE_MS)


def stitch_scroll_container(
    page: Any,
    container: Any,
    metrics: dict[str, int],
    output_path: Path,
) -> None:
    """
    按滚动步长截取容器可视区域并纵向拼接。

    @param page Playwright Page
    @param container 滚动容器 Locator
    @param metrics 滚动尺寸
    @param output_path 输出路径
    """
    step = max(1, metrics["client_height"])
    total_height = min(metrics["scroll_height"], MAX_STITCH_HEIGHT_PX)
    images: list[Image.Image] = []
    scroll_top = 0

    while scroll_top < total_height:
        remaining = total_height - scroll_top
        slice_height = min(step, remaining)

        container.evaluate(f"(el) => {{ el.scrollTop = {scroll_top}; }}")
        page.wait_for_timeout(SCROLL_SETTLE_MS)

        box = container.bounding_box()
        if box is None:
            break

        shot_bytes = page.screenshot(
            clip={
                "x": box["x"],
                "y": box["y"],
                "width": box["width"],
                "height": min(box["height"], slice_height),
            },
        )
        images.append(Image.open(io.BytesIO(shot_bytes)))

        if slice_height < step:
            break

        scroll_top += step

    if not images:
        page.screenshot(path=str(output_path), full_page=True)
        return

    width = max(image.width for image in images)
    height = sum(image.height for image in images)
    canvas = Image.new("RGB", (width, height), color=(255, 255, 255))

    offset_y = 0
    for image in images:
        if image.mode != "RGB":
            image = image.convert("RGB")
        canvas.paste(image, (0, offset_y))
        offset_y += image.height

    canvas.save(output_path, format="PNG", optimize=True)


def screenshot_container_clip(page: Any, container: Any, output_path: Path) -> None:
    """
    对无需滚动的容器直接截取可视区域。

    @param page Playwright Page
    @param container 容器 Locator
    @param output_path 输出路径
    """
    box = container.bounding_box()
    if box is None:
        page.screenshot(path=str(output_path), full_page=True)
        return

    page.screenshot(
        path=str(output_path),
        clip={
            "x": box["x"],
            "y": box["y"],
            "width": box["width"],
            "height": box["height"],
        },
    )
