from geo_monitor_poc.adapters.base import PlatformAdapter
from geo_monitor_poc.models import PlatformId


class DoubaoAdapter(PlatformAdapter):
    """豆包平台适配器。"""

    platform = PlatformId.DOUBAO

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
