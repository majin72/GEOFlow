<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoMonitoring;

/**
 * GEO 监测 sidecar 运行环境：无头 Linux 生产 vs 有头桌面开发/维护。
 */
final class GeoMonitorRuntimeConfig
{
    public const HEADLESS_LINUX = 'headless_linux';

    public const HEADED_DESKTOP = 'headed_desktop';

    /**
     * @param  string  $mode  运行模式
     * @param  string  $pocRoot  POC 根目录
     */
    public function __construct(
        public readonly string $mode,
        public readonly string $pocRoot,
    ) {}

    /**
     * 从应用配置构造。
     */
    public static function fromConfig(): self
    {
        /** @var array<string, mixed> $config */
        $config = config('geoflow.geo_monitor', []);

        $mode = (string) ($config['runtime'] ?? self::HEADLESS_LINUX);

        if (! in_array($mode, [self::HEADLESS_LINUX, self::HEADED_DESKTOP], true)) {
            $mode = self::HEADLESS_LINUX;
        }

        $pocRoot = rtrim((string) ($config['novnc']['poc_root'] ?? base_path('tools/geo-monitor-poc')), '/');

        return new self(mode: $mode, pocRoot: $pocRoot);
    }

    /**
     * 是否为无头 Linux 生产环境（Xvfb + noVNC 维护）。
     */
    public function isHeadlessLinux(): bool
    {
        return $this->mode === self::HEADLESS_LINUX;
    }

    /**
     * 是否为有头桌面环境（macOS / Windows / 有 DISPLAY 的 Linux）。
     */
    public function isHeadedDesktop(): bool
    {
        return $this->mode === self::HEADED_DESKTOP;
    }

    /**
     * 维护事件应记录的入口类型。
     */
    public function maintenanceVia(): string
    {
        return $this->isHeadlessLinux() ? 'novnc' : 'headed_local';
    }

    /**
     * 后台展示用运行模式标签。
     */
    public function label(): string
    {
        return $this->isHeadlessLinux()
            ? __('admin.geo_monitoring.runtime_headless_linux')
            : __('admin.geo_monitoring.runtime_headed_desktop');
    }

    /**
     * 后台展示用运行模式说明。
     */
    public function description(): string
    {
        return $this->isHeadlessLinux()
            ? __('admin.geo_monitoring.runtime_headless_linux_desc')
            : __('admin.geo_monitoring.runtime_headed_desktop_desc');
    }
}
