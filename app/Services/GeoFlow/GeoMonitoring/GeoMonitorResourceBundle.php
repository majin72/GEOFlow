<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoMonitoring;

use App\Models\GeoMonitorAccount;
use App\Models\GeoMonitorBrowserProfile;
use App\Models\GeoMonitorProxyEndpoint;

/**
 * 一次探测分配到的账号 + Profile + 代理组合。
 */
final class GeoMonitorResourceBundle
{
    /**
     * @param  GeoMonitorAccount  $account  采集账号
     * @param  GeoMonitorBrowserProfile|null  $profile  浏览器 Profile
     * @param  GeoMonitorProxyEndpoint|null  $proxy  代理出口
     * @param  string  $schedulerStrategy  调度策略标识
     */
    public function __construct(
        public readonly GeoMonitorAccount $account,
        public readonly ?GeoMonitorBrowserProfile $profile,
        public readonly ?GeoMonitorProxyEndpoint $proxy,
        public readonly string $schedulerStrategy = 'pool_least_busy',
    ) {}

    /**
     * 构造 sidecar probe 请求中的 resource 字段。
     *
     * @return array{account_id: string, profile_id: string, proxy_id: string}
     */
    public function toSidecarResource(): array
    {
        return [
            'account_id' => $this->account->external_id,
            'profile_id' => $this->profile?->profile_key ?? $this->account->external_id,
            'proxy_id' => (string) ($this->proxy?->id ?? $this->account->proxy_endpoint_id ?? ''),
        ];
    }
}
