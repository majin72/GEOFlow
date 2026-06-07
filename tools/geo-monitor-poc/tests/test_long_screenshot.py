from __future__ import annotations

import io
from pathlib import Path

from PIL import Image

from geo_monitor_poc.long_screenshot import stitch_scroll_container


class _FakePage:
    """用于拼接逻辑的轻量 Page 替身。"""

    def __init__(self, frames: list[bytes]) -> None:
        self.frames = frames
        self.index = 0
        self.timeout_calls = 0

    def wait_for_timeout(self, _ms: int) -> None:
        self.timeout_calls += 1

    def screenshot(self, *, clip: dict[str, float]) -> bytes:
        frame = self.frames[min(self.index, len(self.frames) - 1)]
        self.index += 1
        return frame


class _FakeContainer:
    """用于拼接逻辑的轻量 Locator 替身。"""

    def __init__(self, scroll_height: int, client_height: int) -> None:
        self.scroll_height = scroll_height
        self.client_height = client_height
        self.scroll_top = 0

    def evaluate(self, script: str) -> None:
        if "scrollTop = 0" in script:
            self.scroll_top = 0
            return

        if "scrollTop = el.scrollHeight" in script:
            self.scroll_top = self.scroll_height
            return

        marker = "scrollTop = "
        if marker in script:
            value = int(script.split(marker, 1)[1].split(";", 1)[0].strip())
            self.scroll_top = value

    def bounding_box(self) -> dict[str, float]:
        return {
            "x": 10.0,
            "y": 20.0,
            "width": 100.0,
            "height": float(self.client_height),
        }


def _solid_png(color: tuple[int, int, int], size: tuple[int, int]) -> bytes:
    image = Image.new("RGB", size, color=color)
    buffer = io.BytesIO()
    image.save(buffer, format="PNG")
    return buffer.getvalue()


def test_stitch_scroll_container_merges_multiple_frames(tmp_path: Path) -> None:
    """多帧截图应纵向拼接为一张更高的 PNG。"""
    frames = [
        _solid_png((255, 0, 0), (100, 40)),
        _solid_png((0, 255, 0), (100, 40)),
        _solid_png((0, 0, 255), (100, 20)),
    ]
    page = _FakePage(frames)
    container = _FakeContainer(scroll_height=100, client_height=40)
    output = tmp_path / "long.png"

    stitch_scroll_container(
        page,
        container,
        {
            "scroll_height": 100,
            "client_height": 40,
            "scroll_width": 100,
            "client_width": 100,
        },
        output,
    )

    result = Image.open(output)
    assert result.size == (100, 100)
