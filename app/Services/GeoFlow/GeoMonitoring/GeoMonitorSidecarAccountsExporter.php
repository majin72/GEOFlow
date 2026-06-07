<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoMonitoring;

use App\Models\GeoMonitorAccount;
use App\Models\GeoMonitorProxyEndpoint;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * 将 Laravel 后台账号/代理配置导出为 sidecar 使用的 accounts.json。
 */
class GeoMonitorSidecarAccountsExporter
{
    /**
     * 导出 accounts.json 到 POC 根目录。
     *
     * @return string 写入文件的绝对路径
     */
    public function exportToPocRoot(): string
    {
        /** @var array<string, mixed> $novnc */
        $novnc = config('geoflow.geo_monitor.novnc', []);
        $pocRoot = rtrim((string) ($novnc['poc_root'] ?? base_path('tools/geo-monitor-poc')), '/');
        $targetPath = $pocRoot.'/accounts.json';

        if (! is_dir($pocRoot)) {
            throw new RuntimeException(__('admin.geo_monitoring.error.poc_root_missing', ['path' => $pocRoot]));
        }

        $payload = $this->buildPayload();
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new RuntimeException(__('admin.geo_monitoring.error.accounts_export_failed'));
        }

        File::put($targetPath, $json.PHP_EOL);

        return $targetPath;
    }

    /**
     * 构造 sidecar accounts.json 结构。
     *
     * @return array{accounts: list<array<string, mixed>>}
     */
    public function buildPayload(): array
    {
        $accounts = GeoMonitorAccount::query()
            ->with(['platform', 'proxyEndpoint', 'browserProfile'])
            ->orderBy('platform_id')
            ->orderBy('external_id')
            ->get();

        $rows = [];

        foreach ($accounts as $account) {
            $rows[] = $this->accountRow($account);
        }

        return ['accounts' => $rows];
    }

    /**
     * 单账号导出行。
     *
     * @param  GeoMonitorAccount  $account  采集账号
     * @return array<string, mixed>
     */
    private function accountRow(GeoMonitorAccount $account): array
    {
        $profilePath = $this->profileDir($account);

        return [
            'id' => $account->external_id,
            'platform' => $account->platform->code,
            'label' => $account->label !== '' ? $account->label : $account->external_id,
            'profile_dir' => $profilePath,
            'proxy' => $this->proxyUrl($account->proxyEndpoint),
            'locale' => $account->browserProfile?->locale ?? 'zh-CN',
            'timezone_id' => $account->browserProfile?->timezone_id ?? 'Asia/Shanghai',
            'enabled' => in_array($account->status, ['active', 'needs_login', 'needs_maintenance'], true),
        ];
    }

    /**
     * 解析 profile 目录（sidecar 相对路径）。
     *
     * @param  GeoMonitorAccount  $account  采集账号
     */
    private function profileDir(GeoMonitorAccount $account): string
    {
        $path = trim($account->profile_storage_path);

        if ($path === '') {
            return './profiles/'.$account->external_id;
        }

        if (str_starts_with($path, './') || str_starts_with($path, '/')) {
            return $path;
        }

        return './'.$path;
    }

    /**
     * 将代理出口转为 Scrapling 可识别的 proxy URL。
     *
     * @param  GeoMonitorProxyEndpoint|null  $proxy  代理出口
     */
    private function proxyUrl(?GeoMonitorProxyEndpoint $proxy): string
    {
        if ($proxy === null) {
            return '';
        }

        $scheme = $proxy->proxy_type !== '' ? $proxy->proxy_type : 'http';
        $meta = is_array($proxy->meta) ? $proxy->meta : [];
        $username = trim((string) ($meta['username'] ?? ''));
        $password = trim((string) ($meta['password'] ?? ''));

        if ($username !== '') {
            return sprintf(
                '%s://%s:%s@%s:%d',
                $scheme,
                rawurlencode($username),
                rawurlencode($password),
                $proxy->host,
                $proxy->port,
            );
        }

        return sprintf('%s://%s:%d', $scheme, $proxy->host, $proxy->port);
    }
}
