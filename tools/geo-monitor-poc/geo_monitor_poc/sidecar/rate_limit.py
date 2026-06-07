from __future__ import annotations

import time
from collections import defaultdict, deque
from threading import Lock

from geo_monitor_poc.sidecar.errors import SidecarErrorCode, error_body


class TokenBucketRateLimiter:
    """
    按 key（通常为 client token + 路由组）的滑动窗口限频。
    """

    def __init__(self) -> None:
        self._events: dict[str, deque[float]] = defaultdict(deque)
        self._lock = Lock()

    def check(self, key: str, *, limit: int, window_seconds: int = 60) -> dict[str, object] | None:
        """
        检查是否超过限频。

        @param key 限频键
        @param limit 窗口内最大次数
        @param window_seconds 窗口秒数
        @return None 表示通过；否则错误信封
        """
        now = time.time()
        cutoff = now - window_seconds

        with self._lock:
            bucket = self._events[key]
            while bucket and bucket[0] < cutoff:
                bucket.popleft()

            if len(bucket) >= limit:
                retry_after = max(1, int(window_seconds - (now - bucket[0])))
                return error_body(
                    SidecarErrorCode.RATE_LIMITED,
                    f"超过限频：{limit} 次/{window_seconds}s",
                    details={"retry_after_seconds": retry_after},
                )

            bucket.append(now)

        return None
