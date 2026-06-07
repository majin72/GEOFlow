from __future__ import annotations

from geo_monitor_poc.models import PlatformId


class CaptchaRequiredError(Exception):
    """
    无头/非交互模式下检测到验证码，需人工在可见浏览器中处理 profile。
    """

    def __init__(
        self,
        *,
        platform: PlatformId,
        account_id: str,
        stage: str,
    ) -> None:
        """
        @param platform 平台标识
        @param account_id 账号 ID
        @param stage 检测到验证码时的阶段描述
        """
        self.platform = platform
        self.account_id = account_id
        self.stage = stage
        message = (
            f"{platform.value} 账号 {account_id} 在「{stage}」检测到验证码，"
            "需人工处理 profile 后重试"
        )
        super().__init__(message)
