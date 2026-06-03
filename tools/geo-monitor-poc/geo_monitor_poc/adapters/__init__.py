from geo_monitor_poc.adapters.base import PlatformAdapter
from geo_monitor_poc.adapters.deepseek import DeepSeekAdapter
from geo_monitor_poc.adapters.doubao import DoubaoAdapter
from geo_monitor_poc.adapters.yuanbao import YuanbaoAdapter
from geo_monitor_poc.models import AccountConfig, PlatformId

ADAPTER_MAP = {
    PlatformId.DOUBAO: DoubaoAdapter,
    PlatformId.DEEPSEEK: DeepSeekAdapter,
    PlatformId.YUANBAO: YuanbaoAdapter,
}


def create_adapter(account: AccountConfig) -> PlatformAdapter:
    """
    根据账号平台创建适配器实例。

    @param account 账号配置
    @return 平台适配器
    """
    adapter_class = ADAPTER_MAP[account.platform]
    return adapter_class(account)
