<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\GeoFlow\GeoMonitoring\GeoMonitorRuntimeConfig;
use Tests\TestCase;

class GeoMonitorRuntimeConfigTest extends TestCase
{
    /**
     * 默认应为无头 Linux 模式。
     */
    public function test_default_runtime_is_headless_linux(): void
    {
        config(['geoflow.geo_monitor.runtime' => 'headless_linux']);

        $runtime = GeoMonitorRuntimeConfig::fromConfig();

        $this->assertTrue($runtime->isHeadlessLinux());
        $this->assertFalse($runtime->isHeadedDesktop());
        $this->assertSame('novnc', $runtime->maintenanceVia());
    }

    /**
     * 有头桌面模式应使用 headed_local 维护入口。
     */
    public function test_headed_desktop_runtime(): void
    {
        config(['geoflow.geo_monitor.runtime' => 'headed_desktop']);

        $runtime = GeoMonitorRuntimeConfig::fromConfig();

        $this->assertTrue($runtime->isHeadedDesktop());
        $this->assertSame('headed_local', $runtime->maintenanceVia());
    }

    /**
     * 非法 runtime 值应回退为 headless_linux。
     */
    public function test_invalid_runtime_falls_back_to_headless_linux(): void
    {
        config(['geoflow.geo_monitor.runtime' => 'invalid_mode']);

        $runtime = GeoMonitorRuntimeConfig::fromConfig();

        $this->assertTrue($runtime->isHeadlessLinux());
    }
}
